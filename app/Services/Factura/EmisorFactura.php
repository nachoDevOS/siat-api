<?php

namespace App\Services\Factura;

use App\Exceptions\CufdVencidoException;
use App\Exceptions\FacturaInvalidaException;
use App\Jobs\EnviarFacturaAlSiat;
use App\Models\Cufd;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\PuntoVenta;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Orquesta la emision SINCRONA de una factura (pasos 1 al 11 del documento).
 *
 * El objetivo es responder al cajero en ~150 ms: se valida, se reserva el
 * numero, se calcula el CUF, se arma y firma el XML y se guarda en PENDIENTE.
 * El envio al SIAT (lento) queda para un worker en segundo plano.
 */
class EmisorFactura
{
    public function __construct(
        private readonly ValidadorFactura $validador,
        private readonly CalculadorTotales $calculador,
        private readonly GeneradorCuf $generadorCuf,
        private readonly ConstructorXml $constructorXml,
        private readonly FirmadorXml $firmadorXml,
        private readonly ResolutorActividad $resolutor,
    ) {}

    /**
     * Emite una factura para una empresa a partir de la venta normalizada.
     *
     * @param  array<string, mixed>  $venta
     *
     * @throws FacturaInvalidaException si la venta no pasa las reglas locales.
     * @throws CufdVencidoException si el punto de venta no tiene CUFD vigente.
     */
    public function emitir(Empresa $empresa, array $venta): Factura
    {
        $referencia = $venta['referencia_externa'] ?? null;

        // Idempotencia: si esa referencia ya genero factura, se devuelve esa.
        $existente = $this->buscarExistente($empresa, $referencia);

        if ($existente !== null) {
            return $existente;
        }

        // Reglas de negocio que el FormRequest no cubre. Se corta antes de
        // reservar numero para no dejar huecos en el correlativo.
        $errores = $this->validador->validar($venta);

        if ($errores !== []) {
            throw new FacturaInvalidaException($errores);
        }

        $puntoVenta = $this->resolverPuntoVenta($empresa, $venta);

        // CUFD vigente (capa reactiva). Si no hay, aca se solicitaria uno nuevo
        // al SIAT; sin CUFD no se puede calcular el CUF, asi que se corta.
        $cufd = $puntoVenta->cufdVigente();

        if ($cufd === null) {
            throw new CufdVencidoException(
                "El punto de venta {$puntoVenta->codigo_punto_venta} no tiene un CUFD vigente.",
            );
        }

        // La actividad economica de cada item y la leyenda de la cabecera son
        // obligatorias en el XSD del SIN y no vienen en la venta: se deducen de
        // los catalogos del NIT antes de reservar numero, porque un producto no
        // homologado corta la emision.
        $actividades = $this->resolverActividades($empresa, $venta['items']);

        $totales = $this->calculador->calcular(
            $venta['items'],
            (float) ($venta['descuento_global'] ?? 0),
            (float) ($venta['gift_card'] ?? 0),
            (float) ($venta['anticipo'] ?? 0),
        );

        try {
            return $this->emitirEnTransaccion($empresa, $puntoVenta, $cufd, $venta, $totales, $actividades);
        } catch (UniqueConstraintViolationException $e) {
            // Dos peticiones con la misma referencia entraron a la vez: la que
            // perdio la carrera devuelve la factura que gano, no un error.
            $ganadora = $this->buscarExistente($empresa, $referencia);

            if ($ganadora !== null) {
                return $ganadora;
            }

            throw $e;
        }
    }

    /**
     * Reserva el numero, crea la factura y encola su envio, todo en una sola
     * transaccion con bloqueo de fila del punto de venta: asi dos cajas nunca
     * reservan el mismo numero de factura.
     *
     * @param  array<string, mixed>  $venta
     * @param  array<string, mixed>  $totales
     * @param  list<string|null>  $actividades  actividad economica por item.
     */
    private function emitirEnTransaccion(
        Empresa $empresa,
        PuntoVenta $puntoVenta,
        Cufd $cufd,
        array $venta,
        array $totales,
        array $actividades,
    ): Factura {
        return DB::transaction(function () use ($empresa, $puntoVenta, $cufd, $venta, $totales, $actividades) {
            $numero = $this->reservarNumero($puntoVenta);
            $fecha = now();

            $cuf = $this->generadorCuf->generar([
                'nit' => $empresa->nit,
                'fecha' => $fecha->format('YmdHisv'),
                'sucursal' => $puntoVenta->sucursal->codigo_sucursal,
                'modalidad' => $empresa->codigo_modalidad,
                'tipo_emision' => Factura::EMISION_EN_LINEA,
                'tipo_factura' => config('siat.codigos.tipo_factura_documento'),
                'tipo_documento_sector' => config('siat.codigos.documento_sector'),
                'numero_factura' => $numero,
                'punto_venta' => $puntoVenta->codigo_punto_venta,
            ], $cufd->codigo_control);

            $factura = $this->crearFactura(
                $empresa, $puntoVenta, $cufd, $venta, $totales, $numero, $cuf, $fecha, $actividades,
            );

            // Arma y firma el XML con el certificado activo de la empresa. La
            // modalidad electronica NO admite un documento sin firmar: si no hay
            // certificado se corta aca en vez de emitir algo que el SIN rechaza.
            $xml = $this->constructorXml->construir($factura);
            $certificado = $empresa->certificadoActivo;

            if ($certificado === null) {
                throw new FacturaInvalidaException([
                    'La empresa no tiene un certificado digital activo: no se puede firmar la factura.',
                ]);
            }

            $xml = $this->firmadorXml->firmar($xml, $certificado);

            $factura->update(['xml_firmado' => $xml]);

            // Del paso 12 en adelante es asincrono: el worker envia al SIAT.
            // afterCommit para que el worker no busque una factura que todavia
            // no existe (o que un rollback posterior deje sin existir).
            EnviarFacturaAlSiat::dispatch($factura->id)->afterCommit();

            return $factura;
        });
    }

    /**
     * Resuelve el punto de venta por sus codigos del SIN, no por id interno.
     *
     * Se exige 'activo': un punto de venta dado de baja ante el SIN sigue en la
     * tabla por su historial de facturas, pero emitir con el produce facturas
     * que el SIN rechaza. Mejor cortar aca con un 404 claro.
     *
     * @param  array<string, mixed>  $venta
     */
    private function resolverPuntoVenta(Empresa $empresa, array $venta): PuntoVenta
    {
        return PuntoVenta::query()
            ->whereHas('sucursal', function ($q) use ($empresa, $venta) {
                $q->where('empresa_id', $empresa->id)
                    ->where('codigo_sucursal', $venta['sucursal'] ?? 0);
            })
            ->where('codigo_punto_venta', $venta['punto_venta'] ?? 0)
            ->where('activo', true)
            ->firstOrFail();
    }

    /**
     * Reserva y devuelve el siguiente numero de factura con bloqueo de fila.
     */
    private function reservarNumero(PuntoVenta $puntoVenta): int
    {
        $bloqueado = PuntoVenta::whereKey($puntoVenta->id)->lockForUpdate()->first();
        $numero = (int) $bloqueado->siguiente_factura;

        $bloqueado->update(['siguiente_factura' => $numero + 1]);

        return $numero;
    }

    /**
     * Actividad economica de cada item, en el mismo orden que los items.
     *
     * Un producto ausente del catalogo solo es un error cuando la empresa YA
     * sincronizo sus productos homologados: con el catalogo vacio (cliente
     * recien dado de alta) bloquear la emision dejaria el sistema inusable.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<string|null>
     *
     * @throws FacturaInvalidaException si un producto no esta homologado.
     */
    private function resolverActividades(Empresa $empresa, array $items): array
    {
        $exigir = $this->resolutor->tieneCatalogoDeProductos($empresa);
        $actividades = [];
        $errores = [];

        foreach ($items as $i => $item) {
            $actividad = $this->resolutor->actividadDeProducto($empresa, $item['codigo_producto_sin']);

            if ($actividad === null && $exigir) {
                $errores[] = "Item {$i}: el codigo de producto {$item['codigo_producto_sin']} no esta homologado por el SIN para este NIT.";
            }

            $actividades[] = $actividad;
        }

        if ($errores !== []) {
            throw new FacturaInvalidaException($errores);
        }

        return $actividades;
    }

    /**
     * @param  array<string, mixed>  $venta
     * @param  array<string, mixed>  $totales
     * @param  list<string|null>  $actividades
     */
    private function crearFactura(
        Empresa $empresa,
        PuntoVenta $puntoVenta,
        Cufd $cufd,
        array $venta,
        array $totales,
        int $numero,
        string $cuf,
        Carbon $fecha,
        array $actividades,
    ): Factura {
        $comprador = $venta['comprador'];

        $factura = Factura::create([
            'empresa_id' => $empresa->id,
            'punto_venta_id' => $puntoVenta->id,
            'cufd_id' => $cufd->id,
            'cuf' => $cuf,
            'numero_factura' => $numero,
            'fecha_emision' => $fecha,
            'comprador_tipo_documento' => $comprador['tipo_documento'],
            'comprador_numero_documento' => $comprador['numero_documento'],
            'comprador_complemento' => $comprador['complemento'] ?? null,
            'comprador_razon_social' => $comprador['razon_social'],
            'comprador_email' => $comprador['email'] ?? null,
            'metodo_pago' => $venta['metodo_pago'],
            'numero_tarjeta' => $venta['numero_tarjeta'] ?? null,
            'moneda' => $venta['moneda'] ?? 1,
            'tipo_cambio' => $venta['tipo_cambio'] ?? 1,
            'subtotal' => $totales['subtotal'],
            'descuento_global' => $totales['descuento_global'],
            'gift_card' => $totales['gift_card'],
            'anticipo' => $totales['anticipo'],
            'monto_total' => $totales['monto_total'],
            'monto_total_moneda' => $totales['monto_total'],
            'monto_total_sujeto_iva' => $totales['monto_total_sujeto_iva'],
            // La leyenda la puede imponer el cliente; si no, sale del catalogo
            // de leyendas de la actividad del primer item, que es lo que el SIN
            // espera ver en la cabecera.
            'leyenda' => $venta['leyenda']
                ?? $this->resolutor->leyendaDeActividad($empresa, $actividades[0] ?? null),
            'usuario' => $venta['usuario'] ?? null,
            'codigo_documento_sector' => config('siat.codigos.documento_sector'),
            'tipo_emision' => Factura::EMISION_EN_LINEA,
            'estado' => Factura::ESTADO_PENDIENTE,
            'referencia_externa' => $venta['referencia_externa'] ?? null,
        ]);

        foreach ($venta['items'] as $i => $item) {
            $factura->items()->create([
                'codigo_producto_sin' => $item['codigo_producto_sin'],
                'codigo_actividad' => $actividades[$i] ?? null,
                'codigo_interno' => $item['codigo_interno'] ?? null,
                'descripcion' => $item['descripcion'],
                'cantidad' => $item['cantidad'],
                'unidad_medida' => $item['unidad_medida'],
                'precio_unitario' => $item['precio_unitario'],
                'descuento' => $item['descuento'] ?? 0,
                'subtotal' => $totales['items'][$i]['subtotal'],
                'numero_serie' => $item['numero_serie'] ?? null,
                'numero_imei' => $item['numero_imei'] ?? null,
            ]);
        }

        return $factura;
    }

    private function buscarExistente(Empresa $empresa, ?string $referencia): ?Factura
    {
        if (blank($referencia)) {
            return null;
        }

        return Factura::where('empresa_id', $empresa->id)
            ->where('referencia_externa', $referencia)
            ->first();
    }
}
