<?php

namespace Database\Factories;

use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Empresa>
 */
class EmpresaFactory extends Factory
{
    /**
     * Estado por defecto: empresa de piloto recien registrada.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre_comercial' => fake()->company(),
            'razon_social' => mb_strtoupper(fake()->company()),
            'nit' => (string) fake()->numberBetween(1000000, 9999999),
            'codigo_sistema' => Str::upper(Str::random(30)),
            'token_delegado' => Str::random(40),
            // Guardamos el hash de una API key generada al vuelo.
            'api_key_hash' => hash('sha256', Str::random(48)),
            'codigo_ambiente' => 2, // Piloto
            'codigo_modalidad' => 1, // Electronica
            'estado' => Empresa::ESTADO_EN_REGISTRO,
            'webhook_url' => null,
        ];
    }

    /**
     * Empresa ya habilitada para facturar en produccion.
     */
    public function enProduccion(): static
    {
        return $this->state(fn (): array => [
            'codigo_ambiente' => 1,
            'estado' => Empresa::ESTADO_PRODUCCION,
        ]);
    }
}
