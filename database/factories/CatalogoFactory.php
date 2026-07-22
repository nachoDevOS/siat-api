<?php

namespace Database\Factories;

use App\Models\Catalogo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Catalogo>
 */
class CatalogoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tipo' => 'unidades_medida',
            'codigo_clasificador' => (string) fake()->unique()->numberBetween(1, 9999),
            'descripcion' => fake()->word(),
        ];
    }
}
