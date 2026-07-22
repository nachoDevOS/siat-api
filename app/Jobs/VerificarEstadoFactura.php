<?php

namespace App\Jobs;

use App\Exceptions\SiatException;
use App\Models\Factura;
use App\Services\Siat\ServicioFacturacion;
use App\Services\Siat\SiatClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Consulta al SIAT el estado final de una factura ya enviada y lo refleja
 * (VALIDADA u OBSERVADA), avisando al cliente por webhook.
 */
class VerificarEstadoFactura implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [60, 120, 300, 600];

    public function __construct(public readonly int $facturaId) {}

    public function handle(): void
    {
        $factura = Factura::with(['empresa', 'puntoVenta.sucursal', 'cufd'])->find($this->facturaId);

        if ($factura === null || $factura->estado === Factura::ESTADO_VALIDADA) {
            return;
        }

        $cufd = $factura->cufd?->codigo ?? optional($factura->puntoVenta->cufdVigente())->codigo;

        try {
            $servicio = new ServicioFacturacion($factura->empresa, new SiatClient($factura->empresa));
            $respuesta = $servicio->verificarEstado($factura, (string) $cufd);

            $codigoEstado = (string) data_get($respuesta, 'RespuestaServicioFacturacion.codigoEstado');

            // El SIN usa 908/902 (validada) segun operacion; verificar codigos
            // exactos contra el WSDL. Aca se mapea a nuestro estado interno.
            $validada = in_array($codigoEstado, ['908', '902', '901'], true);

            $factura->update([
                'codigo_estado_siat' => $codigoEstado,
                'estado' => $validada ? Factura::ESTADO_VALIDADA : Factura::ESTADO_OBSERVADA,
                'validada_en' => $validada ? now() : null,
            ]);

            NotificarWebhook::dispatch(
                $factura->id,
                $validada ? 'factura.validada' : 'factura.observada',
            );
        } catch (SiatException) {
            // Aun sin respuesta: se reintenta por el backoff del job.
            $this->release(120);
        }
    }
}
