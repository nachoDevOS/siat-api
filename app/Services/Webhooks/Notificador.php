<?php

namespace App\Services\Webhooks;

use App\Jobs\EntregarWebhook;
use App\Models\Empresa;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEntrega;
use Illuminate\Support\Str;

/**
 * Reparte un evento entre los destinos de webhook de la empresa que lo
 * escuchan: una entrega por destino, cada una con su historial y sus
 * reintentos (EntregarWebhook).
 *
 * El cuerpo se arma una sola vez por destino y se guarda: lo que se firma,
 * se envia y se reenvia son siempre los mismos bytes.
 */
class Notificador
{
    /**
     * @param  array<string, mixed>  $datos  lo propio del evento.
     * @return int cantidad de entregas creadas.
     */
    public function emitir(Empresa $empresa, string $evento, array $datos): int
    {
        $destinos = WebhookEndpoint::where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->get()
            ->filter(fn (WebhookEndpoint $destino) => $destino->escucha($evento));

        foreach ($destinos as $destino) {
            $uuid = (string) Str::uuid();

            $entrega = WebhookEntrega::create([
                'uuid' => $uuid,
                'webhook_endpoint_id' => $destino->id,
                'evento' => $evento,
                'cuerpo' => json_encode([
                    'id' => $uuid,
                    'evento' => $evento,
                    // En el sandbox, para que el receptor no mezcle pruebas con lo real.
                    'ambiente' => $empresa->esSandbox() ? 'PILOTO' : 'PRODUCCION',
                    'emitido_en' => now()->toIso8601String(),
                    'datos' => $datos,
                ]),
            ]);

            // afterCommit: si se emite dentro de una transaccion (cierre de
            // contingencia), el worker no debe buscar una entrega sin guardar.
            EntregarWebhook::dispatch($entrega->id)->afterCommit();
        }

        return $destinos->count();
    }
}
