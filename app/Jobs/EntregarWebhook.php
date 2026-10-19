<?php

namespace App\Jobs;

use App\Models\WebhookEntrega;
use App\Services\Webhooks\DestinoWebhook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Entrega un aviso a un destino de webhook registrado y deja el resultado en
 * su historial (webhook_entregas).
 *
 * Firma con el secreto PROPIO del destino: HMAC-SHA256 del cuerpo exacto en
 * X-Siat-Signature. X-Siat-Entrega lleva el id de la entrega para que el
 * receptor deduplique: un reintento o un reenvio manual llegan con el mismo.
 */
class EntregarWebhook implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $entregaId) {}

    public function tries(): int
    {
        return count(config('siat.webhooks.reintentos')) + 1;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return config('siat.webhooks.reintentos');
    }

    public function handle(DestinoWebhook $destino): void
    {
        $entrega = WebhookEntrega::with('endpoint')->find($this->entregaId);

        if ($entrega === null || $entrega->estado !== WebhookEntrega::ESTADO_PENDIENTE) {
            return;
        }

        $endpoint = $entrega->endpoint;

        // Un destino desactivado no recibe: la entrega queda como historial.
        if (! $endpoint->activo) {
            $entrega->update(['estado' => WebhookEntrega::ESTADO_FALLIDA, 'error' => 'El destino esta desactivado.']);

            return;
        }

        // La URL la eligio el cliente: se valida en cada entrega (el DNS pudo
        // cambiar) y se conecta a la IP validada, sin seguir redirecciones.
        ['motivo' => $motivo, 'fijar' => $fijar] = $destino->evaluar($endpoint->url);

        if ($motivo !== null) {
            $entrega->update(['estado' => WebhookEntrega::ESTADO_FALLIDA, 'error' => $motivo, 'intentos' => $entrega->intentos + 1]);

            return;
        }

        $entrega->increment('intentos');

        try {
            $respuesta = Http::timeout((int) config('siat.webhooks.timeout'))
                ->withoutRedirecting()
                ->when($fijar !== null, fn ($http) => $http->withOptions(['curl' => [CURLOPT_RESOLVE => [$fijar]]]))
                ->withHeaders([
                    'X-Siat-Evento' => $entrega->evento,
                    'X-Siat-Entrega' => $entrega->uuid,
                    'X-Siat-Signature' => 'sha256='.hash_hmac('sha256', $entrega->cuerpo, $endpoint->secreto),
                ])
                ->withBody($entrega->cuerpo, 'application/json')
                ->post($endpoint->url);
        } catch (ConnectionException $e) {
            $entrega->update(['codigo_http' => null, 'error' => $e->getMessage()]);

            throw $e;
        }

        if ($respuesta->successful()) {
            $entrega->update([
                'estado' => WebhookEntrega::ESTADO_ENTREGADA,
                'codigo_http' => $respuesta->status(),
                'error' => null,
                'entregada_en' => now(),
            ]);

            return;
        }

        $entrega->update(['codigo_http' => $respuesta->status(), 'error' => mb_substr($respuesta->body(), 0, 500)]);

        // Se lanza para que la cola reintente con el backoff.
        throw new RuntimeException("El destino respondio {$respuesta->status()}.");
    }

    /**
     * Agotados los reintentos queda FALLIDA; el cliente la puede reenviar.
     */
    public function failed(?Throwable $e): void
    {
        WebhookEntrega::whereKey($this->entregaId)
            ->where('estado', WebhookEntrega::ESTADO_PENDIENTE)
            ->update(['estado' => WebhookEntrega::ESTADO_FALLIDA]);
    }
}
