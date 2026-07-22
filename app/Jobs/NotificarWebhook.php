<?php

namespace App\Jobs;

use App\Models\Factura;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Avisa al sistema de ventas del cliente que una factura cambio de estado
 * (seccion 10.6). Solo se dispara si la empresa configuro un webhook_url.
 */
class NotificarWebhook implements ShouldQueue
{
    use Queueable;

    // Reintentos con espera creciente: el sistema del cliente puede estar caido.
    public int $tries = 5;

    public array $backoff = [10, 30, 60, 120];

    public function __construct(
        public readonly int $facturaId,
        public readonly string $evento,
    ) {}

    public function handle(): void
    {
        $factura = Factura::with('empresa')->find($this->facturaId);

        if ($factura === null || blank($factura->empresa->webhook_url)) {
            return;
        }

        Http::timeout(10)->post($factura->empresa->webhook_url, [
            'evento' => $this->evento,
            'cuf' => $factura->cuf,
            'referencia_externa' => $factura->referencia_externa,
            'estado' => $factura->estado,
            'codigo_recepcion' => $factura->codigo_recepcion,
        ])->throw();
    }
}
