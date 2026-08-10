<?php

namespace App\Jobs;

use App\Exceptions\SiatException;
use App\Models\Factura;
use App\Services\Contingencia\GestorContingencia;
use App\Services\Factura\GeneradorPdf;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\RespuestaSiat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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
        // El SIN pide tambien el CUIS en la recepcion de la factura.
        $cuis = optional($factura->puntoVenta->cuisVigente())->codigo;

        try {
            $respuesta = RespuestaSiat::desde(
                $fabrica->facturacion($factura->empresa)
                    ->recepcionarFactura($factura, (string) $cufd, (string) $cuis),
            );

            // El SIN no usa SoapFault para rechazar un documento: responde 200
            // con transaccion=false. Un rechazo es del documento (hash, firma,
            // XML fuera de orden), asi que reintentarlo o derivarlo a
            // contingencia no lo arregla: se observa y se avisa al cliente.
            if (! $respuesta->aceptada) {
                $this->observar($factura, $respuesta);

                return;
            }

            // El SIN acuso recibo de ESTA factura: pasa a RECIBIDA. ENVIADA
            // queda para las que viajan dentro de un paquete de contingencia,
            // donde el acuse es del paquete y no de cada factura.
            $factura->update([
                'estado' => Factura::ESTADO_RECIBIDA,
                'enviada_en' => now(),
                'codigo_recepcion' => $respuesta->codigoRecepcion,
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

    /**
     * Deja constancia del rechazo del SIN y avisa al cliente. No se reintenta:
     * el mismo documento va a ser rechazado igual hasta que se corrija.
     */
    private function observar(Factura $factura, RespuestaSiat $respuesta): void
    {
        $factura->update([
            'estado' => Factura::ESTADO_OBSERVADA,
            'codigo_estado_siat' => $respuesta->codigoEstado,
        ]);

        Log::warning('El SIN rechazo la recepcion de la factura.', [
            'factura_id' => $factura->id,
            'cuf' => $factura->cuf,
            'motivo' => $respuesta->motivo(),
        ]);

        NotificarWebhook::dispatch($factura->id, 'factura.observada');
    }
}
