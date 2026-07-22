<?php

namespace Database\Factories;

use App\Models\Cafc;
use App\Models\PuntoVenta;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Cafc>
 */
class CafcFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'punto_venta_id' => PuntoVenta::factory(),
            'codigo' => Str::upper(Str::random(12)),
            'cantidad_facturas' => 1000,
            'facturas_usadas' => 0,
            'fecha_vigencia' => now()->addMonths(2),
        ];
    }
}
