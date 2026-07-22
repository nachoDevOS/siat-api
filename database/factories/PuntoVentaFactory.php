<?php

namespace Database\Factories;

use App\Models\PuntoVenta;
use App\Models\Sucursal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PuntoVenta>
 */
class PuntoVentaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sucursal_id' => Sucursal::factory(),
            'codigo_punto_venta' => 0,
            'nombre' => 'Caja Principal',
            'tipo_punto_venta' => 1,
            'siguiente_factura' => 1,
            'activo' => true,
        ];
    }
}
