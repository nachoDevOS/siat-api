<?php

namespace Database\Factories\Sitio;

use App\Models\Sitio\SolicitudContacto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SolicitudContacto>
 */
class SolicitudContactoFactory extends Factory
{
    protected $model = SolicitudContacto::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->name(),
            'empresa' => fake()->company(),
            'correo' => fake()->safeEmail(),
            'telefono' => '7'.fake()->numerify('#######'),
            'interes' => 'integrar',
            'mensaje' => 'Tenemos un sistema de ventas propio y queremos facturar en linea.',
            'ip' => '200.87.10.4',
        ];
    }

    public function atendida(): static
    {
        return $this->state(fn (): array => ['atendida_en' => now()]);
    }
}
