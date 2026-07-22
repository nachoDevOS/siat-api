<?php

namespace Database\Factories;

use App\Models\Factura;
use App\Models\FacturaItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FacturaItem>
 */
class FacturaItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cantidad = fake()->numberBetween(1, 100);
        $precio = fake()->randomFloat(2, 1, 500);

        return [
            'factura_id' => Factura::factory(),
            'codigo_producto_sin' => fake()->numberBetween(1, 99999),
            'codigo_interno' => strtoupper(fake()->bothify('???-##')),
            'descripcion' => fake()->words(3, true),
            'cantidad' => $cantidad,
            'unidad_medida' => 57,
            'precio_unitario' => $precio,
            'descuento' => 0,
            'subtotal' => round($cantidad * $precio, 2),
            'numero_serie' => null,
            'numero_imei' => null,
        ];
    }
}
