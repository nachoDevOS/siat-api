<?php

namespace App\Http\Resources;

use App\Models\PuntoVenta;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forma en que la API expone un punto de venta habilitado del cliente.
 *
 * @mixin PuntoVenta
 */
class PuntoVentaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'sucursal' => $this->sucursal->codigo_sucursal,
            'punto_venta' => $this->codigo_punto_venta,
            'nombre' => $this->nombre,
            'tipo_punto_venta' => $this->tipo_punto_venta,
            'activo' => $this->activo,
            'tiene_cufd_vigente' => $this->cufdVigente() !== null,
        ];
    }
}
