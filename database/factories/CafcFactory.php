<?php

namespace Database\Factories;

use App\Models\Cafc;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

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
            'empresa_id' => Empresa::factory(),
            'codigo' => strtoupper(fake()->unique()->bothify('1014##??####?')),
            'numero_inicio' => 1,
            'numero_fin' => 1000,
            'ultimo_numero' => null,
            'activo' => true,
        ];
    }
}
