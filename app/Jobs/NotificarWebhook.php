<?php

namespace App\Jobs;

use App\Models\Factura;
use App\Services\Webhooks\DestinoWebhook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Avisa al sistema de ventas del cliente que una factura cambio de estado
 * (seccion 10.6). Solo se dispara si la empresa configuro un webhook_url.
 *
 * El cuerpo va firmado con HMAC-SHA256 para que el receptor pueda comprobar
 * que la notificacion salio de aca y no de un tercero que conoce su URL.
 */
class NotificarWebhook implements ShouldQueue
{
    use Queueable;

    // Reintentos con espera creciente: el sistema del cliente puede estar caido.
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(
        public readonly int $facturaId,
        public readonly string $evento,
    ) {}

    public function handle(DestinoWebhook $destino): void
    {
        $factura = Factura::with('empresa')->find($this->facturaId);

        if ($factura === null || blank($factura->empresa->webhook_url)) {
            return;
        }

        $url = $factura->empresa->webhook_url;

        // La URL la eligio el cliente: si apunta a la red interna se descarta
        // sin reintentar, porque reintentar no la va a volver segura.
        $motivo = $destino->motivoRechazo($url);

        if ($motivo !== null) {
            Log::warning('Webhook descartado por destino no permitido.', [
                'empresa_id' => $factura->empresa_id,
                'motivo' => $motivo,
            ]);

            return;
        }

        $cuerpo = [
            'evento' => $this->evento,
            'cuf' => $factura->cuf,
            'referencia_externa' => $factura->referencia_externa,
            'estado' => $factura->estado,
            'codigo_recepcion' => $factura->codigo_recepcion,
            'emitido_en' => now()->toIso8601String(),
        ];

        // Se serializa una sola vez y se envia ese string tal cual: asi la firma
        // cubre exactamente los bytes que recibe el cliente y no una version
        // re-serializada que podria diferir en el orden o el escapado.
        $json = json_encode($cuerpo);

        Http::timeout((int) config('siat.webhooks.timeout'))
            ->withHeaders($this->cabeceras($json))
            ->withBody($json, 'application/json')
            ->post($url)
            ->throw();
    }

    /**
     * Cabeceras de autenticidad sobre el cuerpo exacto que se envia.
     *
     * @return array<string, string>
     */
    private function cabeceras(string $json): array
    {
        $cabeceras = ['X-Siat-Evento' => $this->evento];

        $secreto = config('siat.webhooks.secreto');

        if (blank($secreto)) {
            return $cabeceras;
        }

        $cabeceras['X-Siat-Signature'] = 'sha256='.hash_hmac('sha256', $json, $secreto);

        return $cabeceras;
    }
}
