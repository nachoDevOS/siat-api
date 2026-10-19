<?php

namespace Database\Factories;

use App\Models\WebhookEndpoint;
use App\Models\WebhookEntrega;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEntrega>
 */
class WebhookEntregaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'webhook_endpoint_id' => WebhookEndpoint::factory(),
            'evento' => 'factura.validada',
            'cuerpo' => json_encode(['evento' => 'factura.validada']),
            'estado' => WebhookEntrega::ESTADO_PENDIENTE,
        ];
    }
}
