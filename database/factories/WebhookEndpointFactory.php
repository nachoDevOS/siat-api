<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'url' => 'https://erp.cliente.bo/webhooks/siat',
            'eventos' => ['*'],
            'descripcion' => 'ERP',
            'secreto' => WebhookEndpoint::nuevoSecreto(),
            'activo' => true,
        ];
    }
}
