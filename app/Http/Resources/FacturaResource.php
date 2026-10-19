<?php

namespace App\Http\Resources;

use App\Models\Factura;
use App\Services\Factura\GeneradorQr;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forma en que la API devuelve una factura al sistema de ventas (seccion 10.3).
 *
 * @mixin Factura
 */
class FacturaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $esNota = $this->resource->esNotaCreditoDebito();

        return [
            'tipo_documento' => $esNota ? 'NOTA_CREDITO_DEBITO' : 'FACTURA',
            'documento_sector' => $this->codigo_documento_sector,
            'cuf' => $this->cuf,
            // En una nota es su numero de nota, de un correlativo propio.
            'numero_factura' => $this->numero_factura,
            'sucursal' => $this->puntoVenta->sucursal->codigo_sucursal,
            'punto_venta' => $this->puntoVenta->codigo_punto_venta,
            'fecha_emision' => $this->fecha_emision?->toIso8601String(),
            'estado' => $this->estado,
            'codigo_estado_siat' => $this->codigo_estado_siat,
            // Por que el SIN la rechazo; null salvo en OBSERVADA.
            'motivo_observacion' => $this->motivo_observacion,
            'leyenda' => $this->leyenda,
            'total' => (float) $this->monto_total,
            // Solo en una nota: que factura ajusta y cuanto credito fiscal
            // revierte (13% del total devuelto).
            'factura_origen' => $this->when($esNota, fn () => [
                'cuf' => $this->facturaOrigen?->cuf,
                'numero_factura' => $this->facturaOrigen?->numero_factura,
            ]),
            'monto_devuelto' => $this->when($esNota, fn () => (float) $this->subtotal),
            'descuento' => $this->when($esNota, fn () => (float) $this->descuento_global),
            'monto_credito_debito' => $this->when($esNota, fn () => (float) $this->monto_efectivo_credito_debito),
            'referencia_externa' => $this->referencia_externa,
            // Solo si se pidio alguna vez la anulacion: estado (PENDIENTE,
            // CONFIRMADA, RECHAZADA, REVERTIDA) y si fue por servicio web o
            // administrativa, con su resolucion.
            'anulacion' => $this->when($this->anulacion !== null, fn () => [
                'tipo' => $this->anulacion->tipo,
                'estado' => $this->anulacion->estado,
                'motivo' => $this->anulacion->motivo,
                'numero_resolucion' => $this->anulacion->numero_resolucion,
                'anulada_en' => $this->anulacion->anulada_en?->toIso8601String(),
            ]),
            'url_verificacion' => app(GeneradorQr::class)->urlVerificacion($this->resource),
            // Enlaces PUBLICOS firmados: se abren sin token. Es lo que el
            // sistema de ventas muestra o imprime, y lo que se le comparte al
            // comprador. La API arma todo.
            'url_publica' => $this->resource->urlPublica(),
            'url_publica_pdf' => $this->resource->urlPublica('pdf'),
            'url_publica_rollo' => $this->resource->urlPublica('rollo'),
            // Mini ticket de caja (monto + QR) y ZIP con XML firmado y PDF.
            'url_publica_mini' => $this->resource->urlPublica('mini'),
            'url_publica_zip' => $this->resource->urlPublica('zip'),
            'url_pdf' => url("/api/v1/facturas/{$this->cuf}/pdf"),
            'url_xml' => url("/api/v1/facturas/{$this->cuf}/xml"),
        ];
    }
}
