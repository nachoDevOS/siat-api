<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\Sucursal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sucursal>
 */
class SucursalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'codigo_sucursal' => Sucursal::CODIGO_CASA_MATRIZ,
            'nombre' => 'Casa Matriz',
            'municipio' => fake()->city(),
            'direccion' => fake()->streetAddress(),
            'telefono' => fake()->numerify('########'),
        ];
    }
}
