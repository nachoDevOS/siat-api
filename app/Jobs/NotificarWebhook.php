<?php

namespace App\Jobs;

use App\Models\Empresa;
use App\Models\Factura;
use App\Services\Webhooks\DestinoWebhook;
use App\Services\Webhooks\Notificador;
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

    public function handle(DestinoWebhook $destino, Notificador $notificador): void
    {
        $factura = Factura::with('empresa')->find($this->facturaId);

        if ($factura === null) {
            return;
        }

        // Los destinos registrados por la API reciben el evento con su propia
        // entrega, historial y reintentos. Solo en el primer intento: si el
        // webhook unico de abajo falla y este job se reintenta, no se repite.
        if ($this->attempts() <= 1) {
            $notificador->emitir($factura->empresa, $this->evento, $this->datos($factura));
        }

        if (blank($factura->empresa->webhook_url)) {
            return;
        }

        $url = $factura->empresa->webhook_url;

        // La URL la eligio el cliente: si apunta a la red interna se descarta
        // sin reintentar, porque reintentar no la va a volver segura.
        ['motivo' => $motivo, 'fijar' => $fijar] = $destino->evaluar($url);

        if ($motivo !== null) {
            Log::warning('Webhook descartado por destino no permitido.', [
                'empresa_id' => $factura->empresa_id,
                'motivo' => $motivo,
            ]);

            return;
        }

        $cuerpo = ['evento' => $this->evento] + $this->datos($factura) + ['emitido_en' => now()->toIso8601String()];

        // Se serializa una sola vez y se envia ese string tal cual: asi la firma
        // cubre exactamente los bytes que recibe el cliente y no una version
        // re-serializada que podria diferir en el orden o el escapado.
        $json = json_encode($cuerpo);

        Http::timeout((int) config('siat.webhooks.timeout'))
            // Sin seguir redirecciones: solo se valido $url. Un 302 a
            // http://169.254.169.254 o a la red interna saltaba el control
            // anti-SSRF, porque el destino nuevo no pasa por DestinoWebhook.
            ->withoutRedirecting()
            // Se conecta a la IP que se acaba de validar y no a lo que el DNS
            // conteste en el segundo siguiente (rebinding).
            ->when($fijar !== null, fn ($http) => $http->withOptions([
                'curl' => [CURLOPT_RESOLVE => [$fijar]],
            ]))
            ->withHeaders($this->cabeceras($factura->empresa, $json))
            ->withBody($json, 'application/json')
            ->post($url)
            ->throw();
    }

    /**
     * Lo que se cuenta de la factura en el aviso.
     *
     * @return array<string, mixed>
     */
    private function datos(Factura $factura): array
    {
        return [
            // Los eventos factura.* llegan tambien para las notas de
            // credito-debito: esto dice cual de los dos es.
            'tipo_documento' => $factura->esNotaCreditoDebito() ? 'NOTA_CREDITO_DEBITO' : 'FACTURA',
            'cuf' => $factura->cuf,
            'referencia_externa' => $factura->referencia_externa,
            'estado' => $factura->estado,
            'codigo_recepcion' => $factura->codigo_recepcion,
            // Para mostrarla o reenviarla al comprador sin token.
            'url_publica' => $factura->urlPublica(),
            // El porque de un rechazo, para que el sistema de ventas sepa que
            // corregir. Null en los eventos que no son rechazos.
            'motivo' => match ($this->evento) {
                'factura.observada' => $factura->motivo_observacion,
                'factura.anulacion_rechazada' => $factura->anulacion?->motivo_rechazo,
                default => null,
            },
        ];
    }

    /**
     * Cabeceras de autenticidad sobre el cuerpo exacto que se envia.
     *
     * @return array<string, string>
     */
    private function cabeceras(Empresa $empresa, string $json): array
    {
        $cabeceras = ['X-Siat-Evento' => $this->evento];

        $secreto = $empresa->secretoWebhook();

        if (blank($secreto)) {
            return $cabeceras;
        }

        $cabeceras['X-Siat-Signature'] = 'sha256='.hash_hmac('sha256', $json, $secreto);

        return $cabeceras;
    }
}
