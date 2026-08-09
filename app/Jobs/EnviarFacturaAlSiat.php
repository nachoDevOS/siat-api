<?php

namespace App\Jobs;

use App\Exceptions\SiatException;
use App\Models\Factura;
use App\Services\Contingencia\GestorContingencia;
use App\Services\Factura\GeneradorPdf;
use App\Services\Siat\FabricaServicios;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Envia una factura ya emitida al SIAT en segundo plano (paso 12 del flujo).
 *
 * El cliente ya recibio su CUF; aca solo se transmite. Si el SIAT no responde,
 * se deriva a contingencia para no bloquear la venta.
 */
class EnviarFacturaAlSiat implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $facturaId) {}

    public function handle(GeneradorPdf $pdf, GestorContingencia $contingencia, FabricaServicios $fabrica): void
    {
        $factura = Factura::with(['empresa', 'puntoVenta.sucursal', 'cufd', 'items'])->find($this->facturaId);

        if ($factura === null || $factura->estado === Factura::ESTADO_VALIDADA) {
            return;
        }

        // El PDF se puede generar ya, no depende del SIAT. En un reintento ya
        // esta hecho y no hace falta volver a renderizarlo.
        if (blank($factura->ruta_pdf)) {
            $factura->update(['ruta_pdf' => $pdf->generarYGuardar($factura)]);
        }

        $cufd = $factura->cufd?->codigo ?? optional($factura->puntoVenta->cufdVigente())->codigo;

        try {
            $respuesta = $fabrica->facturacion($factura->empresa)
                ->recepcionarFactura($factura, (string) $cufd);

            // El codigo de recepcion confirma que el SIN acepto el envio.
            $factura->update([
                'estado' => Factura::ESTADO_ENVIADA,
                'enviada_en' => now(),
                'codigo_recepcion' => (string) data_get($respuesta, 'RespuestaServicioFacturacion.codigoRecepcion'),
            ]);

            // Se consulta el estado final (validada/observada) por separado.
            VerificarEstadoFactura::dispatch($factura->id)->delay(now()->addSeconds(15));
        } catch (SiatException) {
            // Un hipo de red no es una caida del SIAT: primero se agotan los
            // reintentos con backoff y recien en el ultimo intento se abre el
            // evento significativo y se deriva a contingencia.
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);

                return;
            }

            // El SIAT sigue sin responder: la factura pasa a contingencia y
            // sigue siendo valida, solo queda pendiente de transmitir.
            $contingencia->derivar($factura);
        }
    }
}
