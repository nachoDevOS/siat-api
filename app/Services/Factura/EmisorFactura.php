<?php

namespace App\Services\Factura;

use App\Exceptions\CufdVencidoException;
use App\Jobs\EnviarFacturaAlSiat;
use App\Models\Cufd;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\PuntoVenta;
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
    ) {}

    /**
     * Emite una factura para una empresa a partir de la venta normalizada.
     *
     * @param  array<string, mixed>  $venta
     *
     * @throws CufdVencidoException si el punto de venta no tiene CUFD vigente.
     */
    public function emitir(Empresa $empresa, array $venta): Factura
    {
        // Idempotencia: si esa referencia ya genero factura, se devuelve esa.
        $existente = $this->buscarExistente($empresa, $venta['referencia_externa'] ?? null);

        if ($existente !== null) {
            return $existente;
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

        $totales = $this->calculador->calcular(
            $venta['items'],
            (float) ($venta['descuento_global'] ?? 0),
            (float) ($venta['gift_card'] ?? 0),
            (float) ($venta['anticipo'] ?? 0),
        );

        // Todo el bloque va en transaccion con bloqueo de fila del punto de venta:
        // asi dos cajas nunca reservan el mismo numero de factura.
        return DB::transaction(function () use ($empresa, $puntoVenta, $cufd, $venta, $totales) {
            $numero = $this->reservarNumero($puntoVenta);
            $fecha = now();

            $cuf = $this->generadorCuf->generar([
                'nit' => $empresa->nit,
                'fecha' => $fecha->format('YmdHisv'),
                'sucursal' => $puntoVenta->sucursal->codigo_sucursal,
                'modalidad' => $empresa->codigo_modalidad,
                'tipo_emision' => Factura::EMISION_EN_LINEA,
                'tipo_factura' => 1,
                'tipo_documento_sector' => config('siat.codigos.documento_sector'),
                'numero_factura' => $numero,
                'punto_venta' => $puntoVenta->codigo_punto_venta,
            ], $cufd->codigo_control);

            $factura = $this->crearFactura($empresa, $puntoVenta, $cufd, $venta, $totales, $numero, $cuf, $fecha);

            // Arma y firma el XML con el certificado activo de la empresa.
            $xml = $this->constructorXml->construir($factura);
            $certificado = $empresa->certificadoActivo;

            if ($certificado !== null) {
                $xml = $this->firmadorXml->firmar($xml, $certificado);
            }

            $factura->update(['xml_firmado' => $xml]);

            // Del paso 12 en adelante es asincrono: el worker envia al SIAT.
            EnviarFacturaAlSiat::dispatch($factura->id);

            return $factura;
        });
    }

    /**
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
     * @param  array<string, mixed>  $venta
     * @param  array<string, mixed>  $totales
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
            'leyenda' => $venta['leyenda'] ?? null,
            'usuario' => $venta['usuario'] ?? null,
            'codigo_documento_sector' => config('siat.codigos.documento_sector'),
            'tipo_emision' => Factura::EMISION_EN_LINEA,
            'estado' => Factura::ESTADO_PENDIENTE,
            'referencia_externa' => $venta['referencia_externa'] ?? null,
        ]);

        foreach ($venta['items'] as $i => $item) {
            $factura->items()->create([
                'codigo_producto_sin' => $item['codigo_producto_sin'],
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
