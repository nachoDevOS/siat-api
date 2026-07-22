<?php

namespace Database\Factories;

use App\Models\CasoPrueba;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CasoPrueba>
 */
class CasoPruebaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fase' => CasoPrueba::FASE_PILOTO,
            'orden' => fake()->numberBetween(1, 17),
            'nombre' => fake()->sentence(3),
            'descripcion' => fake()->sentence(),
            'tipo' => 'verificarComunicacion',
            'payload_ejemplo' => null,
            'obligatorio' => true,
        ];
    }
}
