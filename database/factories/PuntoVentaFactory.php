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
            // Por defecto ya registrado en el SIAT: es la unica condicion en la
            // que se puede emitir, asi que es el caso normal de los tests.
            'registrado_en_siat' => now(),
        ];
    }

    /**
     * Punto de venta creado en local pero todavia no dado de alta en el SIN.
     * Es como queda tras POST /api/v1/puntos-venta.
     */
    public function sinRegistrarEnSiat(): static
    {
        return $this->state(fn (): array => ['registrado_en_siat' => null]);
    }
}
