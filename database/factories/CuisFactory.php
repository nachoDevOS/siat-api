<?php

namespace Database\Factories;

use App\Models\Cuis;
use App\Models\PuntoVenta;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Cuis>
 */
class CuisFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'punto_venta_id' => PuntoVenta::factory(),
            'codigo' => Str::upper(Str::random(10)),
            // Vigencia por defecto: un anio hacia adelante.
            'fecha_vigencia' => now()->addYear(),
        ];
    }

    /**
     * CUIS ya vencido, util para probar la logica de renovacion.
     */
    public function vencido(): static
    {
        return $this->state(fn (): array => [
            'fecha_vigencia' => now()->subDay(),
        ]);
    }
}
