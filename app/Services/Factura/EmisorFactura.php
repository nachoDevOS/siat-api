<?php

namespace App\Services\Factura;

use App\Exceptions\CufdVencidoException;
use App\Exceptions\FacturaInvalidaException;
use App\Exceptions\SiatException;
use App\Jobs\EnviarFacturaAlSiat;
use App\Models\Certificado;
use App\Models\Cufd;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\LoteMasivo;
use App\Models\PuntoVenta;
use App\Services\Contingencia\GestorContingencia;
use App\Services\Siat\GestorCodigos;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
        private readonly GestorContingencia $contingencia,
        private readonly GestorCodigos $codigos,
    ) {}

    /**
     * Segundos durante los que no se vuelve a pedir un CUFD a un punto de
     * venta despues de que el SIN fallo al entregarlo.
     */
    private const ESPERA_TRAS_FALLO_CUFD = 60;

    /**
     * Emite una factura para una empresa a partir de la venta normalizada.
     *
     * @param  array<string, mixed>  $venta
     * @param  bool  $encolarEnvio  false = no despachar EnviarFacturaAlSiat. Lo
     *                              usa el piloto, que envia en el momento para
     *                              leer la respuesta del SIN; encolarlo ademas
     *                              mandaria la misma factura dos veces.
     *
     * @throws FacturaInvalidaException si la venta no pasa las reglas locales.
     * @throws CufdVencidoException si el punto de venta no tiene CUFD vigente.
     */
    public function emitir(Empresa $empresa, array $venta, bool $encolarEnvio = true): Factura
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

        // CUFD vigente, o uno nuevo pedido en el momento (capa reactiva).
        $cufd = $this->obtenerCufd($puntoVenta);

        // La actividad economica de cada item y la leyenda de la cabecera son
        // obligatorias en el XSD del SIN y no vienen en la venta: se deducen de
        // los catalogos del NIT antes de reservar numero, porque un producto no
        // homologado corta la emision.
        $actividades = $this->resolverActividades($empresa, $venta['items']);

        $totales = $this->calculador->calcular(
            $venta['items'],
            (float) ($venta['descuento_global'] ?? 0),
            (float) ($venta['gift_card'] ?? 0),
        );

        try {
            return $this->emitirEnTransaccion($empresa, $puntoVenta, $cufd, $venta, $totales, $actividades, $encolarEnvio);
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
        bool $encolarEnvio,
    ): Factura {
        return DB::transaction(function () use ($empresa, $puntoVenta, $cufd, $venta, $totales, $actividades, $encolarEnvio) {
            $numero = $this->reservarNumero($puntoVenta);
            // Sin milisegundos A PROPOSITO. La fecha entra al CUF con ellos
            // (YmdHisv) y viaja en el XML; pero fecha_emision se guarda como
            // datetime de segundos y el XML salia con .000 mientras el CUF
            // llevaba los reales: el SIN recalcula el CUF desde el XML y lo
            // rechazaba. Con .000 en los dos lados no pueden diferir.
            $fecha = now()->startOfSecond();

            // Con un evento de contingencia abierto, esta factura se emite
            // FUERA DE LINEA. Hay que saberlo antes de calcular el CUF: el tipo
            // de emision es uno de sus nueve campos, asi que decidirlo despues
            // deja un CUF que contradice a la factura y el SIN la rechaza.
            $enContingencia = $this->contingencia->hayContingenciaAbierta($puntoVenta);

            // Lote de emision masiva abierto: la factura tambien nace con su
            // tipo de emision en el CUF (3) y espera el cierre del lote. Se
            // lee con el punto de venta ya bloqueado (reservarNumero): el
            // cierre bloquea la misma fila, asi que una factura no puede caer
            // en un lote que ya se cerro. La contingencia manda: con el SIAT
            // caido no hay a donde mandar el lote.
            $lote = $enContingencia ? null : LoteMasivo::where('punto_venta_id', $puntoVenta->id)
                ->where('estado', LoteMasivo::ESTADO_ABIERTO)
                ->first();

            $tipoEmision = match (true) {
                $enContingencia => Factura::EMISION_CONTINGENCIA,
                $lote !== null => Factura::EMISION_MASIVA,
                default => Factura::EMISION_EN_LINEA,
            };

            $cuf = $this->generadorCuf->generar([
                'nit' => $empresa->nit,
                'fecha' => $fecha->format('YmdHisv'),
                'sucursal' => $puntoVenta->sucursal->codigo_sucursal,
                'modalidad' => $empresa->codigo_modalidad,
                'tipo_emision' => $tipoEmision,
                'tipo_factura' => config('siat.codigos.tipo_factura_documento'),
                'tipo_documento_sector' => config('siat.codigos.documento_sector'),
                'numero_factura' => $numero,
                'punto_venta' => $puntoVenta->codigo_punto_venta,
            ], $cufd->codigo_control);

            $factura = $this->crearFactura(
                $empresa, $puntoVenta, $cufd, $venta, $totales, $numero, $cuf, $fecha, $actividades, $tipoEmision,
            );

            if ($lote !== null) {
                $factura->update(['lote_masivo_id' => $lote->id]);
            }

            // Arma y firma el XML con el certificado activo de la empresa. La
            // modalidad electronica NO admite un documento sin firmar: si no hay
            // certificado se corta aca en vez de emitir algo que el SIN rechaza.
            $xml = $this->constructorXml->construir($factura);
            $xml = $this->firmadorXml->firmar($xml, $this->certificadoFirmable($empresa));

            $factura->update(['xml_firmado' => $xml]);

            // Bajo contingencia no se intenta el envio individual: el SIAT no
            // esta respondiendo y estas facturas viajan juntas en el paquete que
            // arma GestorContingencia::recuperar() cuando el servicio vuelve.
            //
            // En un lote masivo tampoco: viaja en su paquete al cerrar el lote.
            if ($tipoEmision === Factura::EMISION_EN_LINEA && $encolarEnvio) {
                // Del paso 12 en adelante es asincrono: el worker envia al SIAT.
                // afterCommit para que el worker no busque una factura que
                // todavia no existe (o que un rollback posterior deje sin existir).
                EnviarFacturaAlSiat::dispatch($factura->id)->afterCommit();
            }

            return $factura;
        });
    }

    /**
     * Certificado con el que se firma un documento de la empresa. La modalidad
     * electronica NO admite un documento sin firmar: si no hay uno valido se
     * corta aca en vez de emitir algo que el SIN rechaza.
     *
     * Publico porque la nota de credito-debito firma con las mismas reglas.
     *
     * @throws FacturaInvalidaException si no hay certificado usable.
     */
    public function certificadoFirmable(Empresa $empresa): Certificado
    {
        $certificado = $empresa->certificadoActivo;

        if ($certificado === null) {
            throw new FacturaInvalidaException([
                'La empresa no tiene un certificado digital activo: no se puede firmar la factura.',
            ]);
        }

        // Firmar con un certificado vencido no da error aca, pero el SIN
        // rechaza la firma: todas las facturas saldrian observadas.
        if ($certificado->estaVencido()) {
            throw new FacturaInvalidaException([
                "El certificado digital de la empresa vencio el {$certificado->vence_el->format('d/m/Y')}: carga uno vigente para poder firmar.",
            ]);
        }

        // Un autofirmado sirve para el piloto y nada mas: una factura de
        // produccion firmada con el no tiene validez legal.
        if ($certificado->esDePrueba() && $empresa->codigo_ambiente === config('siat.codigos.ambiente.produccion')) {
            throw new FacturaInvalidaException([
                'El certificado activo es un autofirmado de prueba: no se puede facturar en produccion con el. Carga el de la entidad certificadora.',
            ]);
        }

        return $certificado;
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
        $puntoVenta = PuntoVenta::query()
            ->whereHas('sucursal', function ($q) use ($empresa, $venta) {
                $q->where('empresa_id', $empresa->id)
                    ->where('codigo_sucursal', $venta['sucursal'] ?? 0);
            })
            ->where('codigo_punto_venta', $venta['punto_venta'] ?? 0)
            ->where('activo', true)
            ->firstOrFail();

        // Existir en nuestra base no alcanza: el codigo de punto de venta entra
        // al CUF, asi que si el SIN no lo conoce, TODA factura emitida contra el
        // sale con un CUF que el SIN no puede validar y las rechaza en bloque.
        //
        // Se puede llegar aca por POST /api/v1/puntos-venta, que crea el
        // registro local pero no lo da de alta en el SIN: eso lo hace el paso 10
        // del piloto y es irreversible, por eso no se dispara solo.
        if (! $puntoVenta->existeEnElSiat()) {
            throw new FacturaInvalidaException([
                "El punto de venta {$puntoVenta->codigo_punto_venta} de la sucursal ".
                "{$venta['sucursal']} todavia no esta registrado en el SIAT: no se puede emitir contra el.",
            ]);
        }

        return $puntoVenta;
    }

    /**
     * CUFD con el que se emite: el vigente o, si vencio, uno nuevo pedido al
     * SIN en el momento (y el CUIS antes, si tambien falta). Los sistemas de
     * venta no manejan codigos: la venta no se puede caer porque la
     * renovacion preventiva no llego a tiempo.
     *
     * Dos casos NO intentan pedirlo, porque solo agregarian a la venta la
     * espera del timeout del SIAT para terminar igual en error:
     *   - el punto de venta esta en contingencia (el SIAT no responde);
     *   - el SIN fallo hace menos de un minuto al entregarlo.
     *
     * Publico porque la nota de credito-debito consigue su CUFD igual.
     *
     * @throws CufdVencidoException si no hay CUFD y no se pudo conseguir.
     */
    public function obtenerCufd(PuntoVenta $puntoVenta): Cufd
    {
        $vigente = $puntoVenta->cufdVigente();

        if ($vigente !== null) {
            return $vigente;
        }

        $sinCufd = "El punto de venta {$puntoVenta->codigo_punto_venta} no tiene un CUFD vigente";
        $claveFallo = "siat:cufd-fallido:{$puntoVenta->id}";

        if ($this->contingencia->hayContingenciaAbierta($puntoVenta) || Cache::has($claveFallo)) {
            throw new CufdVencidoException("{$sinCufd} y el SIAT no esta respondiendo.");
        }

        try {
            return $this->codigos->asegurarCufd($puntoVenta);
        } catch (SiatException|LockTimeoutException $e) {
            Cache::put($claveFallo, true, self::ESPERA_TRAS_FALLO_CUFD);

            throw new CufdVencidoException("{$sinCufd} y el SIAT no entrego uno nuevo: {$e->getMessage()}", previous: $e);
        }
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
     * @param  int  $tipoEmision  1 en linea, 2 contingencia, 3 masiva. Ya entro al CUF.
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
        int $tipoEmision,
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
            'numero_tarjeta' => $this->enmascararTarjeta($venta['numero_tarjeta'] ?? null),
            'codigo_excepcion' => (int) ($venta['codigo_excepcion'] ?? 0),
            'moneda' => $venta['moneda'] ?? 1,
            'tipo_cambio' => $venta['tipo_cambio'] ?? 1,
            'subtotal' => $totales['subtotal'],
            'descuento_global' => $totales['descuento_global'],
            'gift_card' => $totales['gift_card'],
            'total_precio_neto' => $totales['total_precio_neto'],
            'iva' => $totales['iva'],
            // montoaPagar: neto + IVA, ya sin la gift card.
            'monto_total' => $totales['monto_total'],
            'monto_total_moneda' => $totales['monto_total'],
            // La leyenda la puede imponer el cliente; si no, sale del catalogo
            // de leyendas de la actividad del primer item, que es lo que el SIN
            // espera ver en la cabecera.
            'leyenda' => $venta['leyenda']
                ?? $this->resolutor->leyendaDeActividad($empresa, $actividades[0] ?? null),
            'usuario' => $venta['usuario'] ?? null,
            'codigo_documento_sector' => config('siat.codigos.documento_sector'),
            'tipo_emision' => $tipoEmision,
            // Una factura emitida fuera de linea ya nace en contingencia: es
            // valida e imprimible, solo le falta viajar dentro de un paquete.
            'estado' => match ($tipoEmision) {
                Factura::EMISION_CONTINGENCIA => Factura::ESTADO_CONTINGENCIA,
                Factura::EMISION_MASIVA => Factura::ESTADO_MASIVA,
                default => Factura::ESTADO_PENDIENTE,
            },
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

    /**
     * Deja solo los 4 primeros y los 4 ultimos digitos de la tarjeta, con
     * ceros en el medio, que es como el SIN espera el numeroTarjeta.
     *
     * Se enmascara ANTES de guardar: el numero completo no debe quedar ni en
     * la base ni en el XML firmado (que es permanente y viaja al SIN). Un
     * numero de 8 digitos o menos ya viene parcial y se deja como esta.
     */
    private function enmascararTarjeta(?string $numero): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $numero);

        if ($digitos === '') {
            return null;
        }

        if (strlen($digitos) <= 8) {
            return $digitos;
        }

        return substr($digitos, 0, 4).str_repeat('0', strlen($digitos) - 8).substr($digitos, -4);
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
