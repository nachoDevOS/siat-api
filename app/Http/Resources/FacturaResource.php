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
        return [
            'cuf' => $this->cuf,
            'numero_factura' => $this->numero_factura,
            'sucursal' => $this->puntoVenta->sucursal->codigo_sucursal,
            'punto_venta' => $this->puntoVenta->codigo_punto_venta,
            'fecha_emision' => $this->fecha_emision?->toIso8601String(),
            'estado' => $this->estado,
            'leyenda' => $this->leyenda,
            'total' => (float) $this->monto_total,
            'referencia_externa' => $this->referencia_externa,
            'url_verificacion' => app(GeneradorQr::class)->urlVerificacion($this->resource),
            'url_pdf' => url("/api/v1/facturas/{$this->cuf}/pdf"),
            'url_xml' => url("/api/v1/facturas/{$this->cuf}/xml"),
        ];
    }
}
