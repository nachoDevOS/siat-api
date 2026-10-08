<?php

namespace App\Services\Pruebas;

use App\Exceptions\SiatException;
use App\Models\Cufd;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\FacturaItem;
use App\Models\PuntoVenta;
use App\Services\Contingencia\ArmadorPaquete;
use App\Services\Factura\CalculadorTotales;
use App\Services\Factura\ConstructorXml;
use App\Services\Factura\FirmadorXml;
use App\Services\Factura\GeneradorCuf;
use App\Services\Factura\ResolutorActividad;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Arma el TAR de facturas fuera de linea de una prueba de paquetes (etapa VI).
 *
 * Las facturas se construyen EN MEMORIA y no se guardan: la etapa pide 70
 * paquetes de 500 por punto de venta, y guardarlas llenaria la tabla de
 * facturas con 35.000 documentos de prueba. Lo que si se toca es el
 * correlativo del punto de venta, para que los numeros nunca se repitan con
 * los de las facturas reales.
 *
 * Usa las MISMAS piezas que la emision real (CUF, XML, firma): lo que prueba el
 * SIN es el documento que este sistema produce de verdad.
 */
class GeneradorPaquetePrueba
{
    /**
     * Facturas que se sellan con el mismo segundo. fecha_emision se guarda sin
     * milisegundos (ver EmisorFactura), asi que 500 facturas entran en 50
     * segundos, dentro del minuto que dura el evento. El CUF no se repite
     * porque lleva el numero de factura.
     */
    private const FACTURAS_POR_SEGUNDO = 10;

    public function __construct(
        private readonly CalculadorTotales $calculador,
        private readonly GeneradorCuf $generadorCuf,
        private readonly ConstructorXml $constructorXml,
        private readonly FirmadorXml $firmador,
        private readonly ResolutorActividad $resolutor,
        private readonly ArmadorPaquete $armador,
    ) {}

    /**
     * @param  array<string, mixed>  $venta  venta de prueba (misma forma que la API).
     * @param  Cufd  $cufdEvento  CUFD vigente durante el evento: entra al CUF y al XML.
     * @param  Carbon  $inicio  inicio del evento; las facturas se fechan desde ahi.
     * @return array{tar: string, desde: int, hasta: int}
     */
    public function armar(
        Empresa $empresa,
        PuntoVenta $puntoVenta,
        Cufd $cufdEvento,
        array $venta,
        int $cantidad,
        Carbon $inicio,
    ): array {
        $certificado = $empresa->certificadoActivo;

        if ($certificado === null) {
            throw new SiatException('La empresa no tiene un certificado digital activo: no se pueden firmar las facturas del paquete.');
        }

        $primero = $this->reservarNumeros($puntoVenta, $cantidad);
        $totales = $this->calculador->calcular($venta['items']);
        $actividad = $this->resolutor->actividadDeProducto($empresa, $venta['items'][0]['codigo_producto_sin']);
        $leyenda = $this->resolutor->leyendaDeActividad($empresa, $actividad);
        $puntoVenta->loadMissing('sucursal');

        $archivos = [];

        for ($i = 0; $i < $cantidad; $i++) {
            $numero = $primero + $i;
            $fecha = $inicio->copy()->addSeconds(intdiv($i, self::FACTURAS_POR_SEGUNDO));

            $factura = $this->factura($empresa, $puntoVenta, $cufdEvento, $venta, $totales, $numero, $fecha, $actividad, $leyenda);
            $xml = $this->firmador->firmar($this->constructorXml->construir($factura), $certificado);

            $archivos["{$factura->cuf}.xml"] = $xml;
        }

        return [
            'tar' => $this->armador->tar($archivos),
            'desde' => $primero,
            'hasta' => $primero + $cantidad - 1,
        ];
    }

    /**
     * Reserva un bloque de numeros del correlativo, con el mismo bloqueo de
     * fila que usa la emision real.
     */
    private function reservarNumeros(PuntoVenta $puntoVenta, int $cantidad): int
    {
        return DB::transaction(function () use ($puntoVenta, $cantidad): int {
            $bloqueado = PuntoVenta::whereKey($puntoVenta->id)->lockForUpdate()->first();
            $primero = (int) $bloqueado->siguiente_factura;

            $bloqueado->update(['siguiente_factura' => $primero + $cantidad]);

            return $primero;
        });
    }

    /**
     * Factura fuera de linea, sin guardar, con todo lo que ConstructorXml lee.
     *
     * @param  array<string, mixed>  $venta
     * @param  array<string, mixed>  $totales
     */
    private function factura(
        Empresa $empresa,
        PuntoVenta $puntoVenta,
        Cufd $cufdEvento,
        array $venta,
        array $totales,
        int $numero,
        Carbon $fecha,
        ?string $actividad,
        ?string $leyenda,
    ): Factura {
        $cuf = $this->generadorCuf->generar([
            'nit' => $empresa->nit,
            'fecha' => $fecha->format('YmdHisv'),
            'sucursal' => $puntoVenta->sucursal->codigo_sucursal,
            'modalidad' => $empresa->codigo_modalidad,
            'tipo_emision' => Factura::EMISION_CONTINGENCIA,
            'tipo_factura' => config('siat.codigos.tipo_factura_documento'),
            'tipo_documento_sector' => config('siat.codigos.documento_sector'),
            'numero_factura' => $numero,
            'punto_venta' => $puntoVenta->codigo_punto_venta,
        ], $cufdEvento->codigo_control);

        $comprador = $venta['comprador'];

        $factura = new Factura([
            'cuf' => $cuf,
            'numero_factura' => $numero,
            'fecha_emision' => $fecha,
            'comprador_tipo_documento' => $comprador['tipo_documento'],
            'comprador_numero_documento' => $comprador['numero_documento'],
            'comprador_razon_social' => $comprador['razon_social'],
            'metodo_pago' => $venta['metodo_pago'],
            'moneda' => 1,
            'tipo_cambio' => 1,
            'monto_total' => $totales['monto_total'],
            'monto_total_moneda' => $totales['monto_total'],
            'monto_total_sujeto_iva' => $totales['monto_total_sujeto_iva'],
            'gift_card' => 0,
            'descuento_global' => 0,
            'leyenda' => $leyenda,
            'usuario' => $venta['usuario'] ?? null,
            'codigo_documento_sector' => config('siat.codigos.documento_sector'),
            'tipo_emision' => Factura::EMISION_CONTINGENCIA,
        ]);

        $items = collect($venta['items'])->map(fn (array $item, int $i): FacturaItem => new FacturaItem([
            'codigo_producto_sin' => $item['codigo_producto_sin'],
            'codigo_actividad' => $actividad,
            'codigo_interno' => $item['codigo_interno'] ?? null,
            'descripcion' => $item['descripcion'],
            'cantidad' => $item['cantidad'],
            'unidad_medida' => $item['unidad_medida'],
            'precio_unitario' => $item['precio_unitario'],
            'descuento' => 0,
            'subtotal' => $totales['items'][$i]['subtotal'],
        ]));

        return $factura
            ->setRelation('empresa', $empresa)
            ->setRelation('puntoVenta', $puntoVenta)
            ->setRelation('cufd', $cufdEvento)
            ->setRelation('items', $items);
    }
}
