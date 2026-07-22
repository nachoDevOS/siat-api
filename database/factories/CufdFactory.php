<?php

namespace Database\Factories;

use App\Models\Cufd;
use App\Models\PuntoVenta;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Cufd>
 */
class CufdFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'punto_venta_id' => PuntoVenta::factory(),
            'codigo' => Str::upper(Str::random(16)),
            'codigo_control' => Str::upper(Str::random(8)),
            'direccion' => fake()->streetAddress(),
            // El CUFD dura 24 horas.
            'fecha_vigencia' => now()->addDay(),
        ];
    }

    /**
     * CUFD vencido, para probar la renovacion reactiva al emitir.
     */
    public function vencido(): static
    {
        return $this->state(fn (): array => [
            'fecha_vigencia' => now()->subHour(),
        ]);
    }
}
