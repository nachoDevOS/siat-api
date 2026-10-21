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
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envia una factura ya emitida al SIAT en segundo plano (paso 12 del flujo).
 *
 * El cliente ya recibio su CUF; aca solo se transmite. Si el SIAT no responde,
 * se deriva a contingencia para no bloquear la venta.
 */
#[Queue('facturas')]
class EnviarFacturaAlSiat implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $facturaId) {}

    public function handle(GeneradorPdf $pdf, GestorContingencia $contingencia, FabricaServicios $fabrica): void
    {
        $factura = Factura::with(['empresa', 'puntoVenta.sucursal', 'cufd', 'items'])->find($this->facturaId);

        // Solo se transmite lo que todavia no viajo. Un job duplicado (el
        // scheduler de pendientes + uno atrasado en la cola) no puede reenviar
        // una RECIBIDA u OBSERVADA, ni mandar en linea una que ya cayo a
        // CONTINGENCIA y va a viajar en el paquete, ni revivir una ANULADA.
        if ($factura === null || $factura->estado !== Factura::ESTADO_PENDIENTE) {
            return;
        }

        // El PDF se puede generar ya, no depende del SIAT. En un reintento ya
        // esta hecho y no hace falta volver a renderizarlo.
        //
        // Una falla del PDF NO frena el envio: el documento legal es el XML
        // firmado. Antes una excepcion de dompdf tumbaba el job y la factura
        // nunca llegaba al SIN. Si falta, la descarga lo genera a pedido.
        if (blank($factura->ruta_pdf)) {
            try {
                $factura->update(['ruta_pdf' => $pdf->generarYGuardar($factura)]);
            } catch (Throwable $e) {
                Log::error('No se pudo generar el PDF de la factura; se envia igual.', [
                    'factura_id' => $factura->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $cufd = $factura->cufd?->codigo ?? optional($factura->puntoVenta->cufdVigente())->codigo;
        // El SIN pide tambien el CUIS en la recepcion, y tiene que ser el que
        // genero ese CUFD: con uno mas nuevo responde 913 (PuntoVenta::cuisDe).
        $cuis = $factura->puntoVenta->cuisDe($factura->cufd ?? $factura->puntoVenta->cufdVigente())?->codigo;

        try {
            $respuesta = RespuestaSiat::desde(
                $fabrica->facturacion($factura->empresa, $factura->version)
                    ->recepcionarFactura($factura, (string) $cufd, (string) $cuis),
            );

            // El SIN no usa SoapFault para rechazar un documento: responde 200
            // con transaccion=false. Un rechazo es del documento (hash, firma,
            // XML fuera de orden), asi que reintentarlo o derivarlo a
            // contingencia no lo arregla: se observa y se avisa al cliente.
            if (! $respuesta->aceptada) {
                $this->resolverRechazo($factura, $respuesta, $fabrica, (string) $cufd, (string) $cuis);

                return;
            }

            // En linea el SIN suele validar en la misma respuesta (908). Si ya
            // la dio por validada, preguntarle de nuevo 15 s despues es un
            // viaje de mas y deja al cliente esperando el webhook sin motivo.
            if (in_array($respuesta->codigoEstado, VerificarEstadoFactura::CODIGOS_VALIDADA, true)) {
                $this->marcarValidada($factura, $respuesta);

                return;
            }

            $this->marcarRecibida($factura, $respuesta->codigoRecepcion);
        } catch (SiatException) {
            // Un hipo de red no es una caida del SIAT: primero se agotan los
            // reintentos con backoff y recien en el ultimo intento se abre el
            // evento significativo y se deriva a contingencia.
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);

                return;
            }

            // Una nota de credito-debito no tiene contingencia: el servicio de
            // ajuste del SIN no recibe paquetes. Queda PENDIENTE y la retoma
            // la tarea programada 'reintentar-pendientes'.
            if ($factura->esNotaCreditoDebito()) {
                return;
            }

            // El SIAT sigue sin responder: la factura pasa a contingencia y
            // sigue siendo valida, solo queda pendiente de transmitir.
            $contingencia->derivar($factura);
        }
    }

    /**
     * Un rechazo en la recepcion no siempre es del documento.
     *
     * Si un intento anterior llego al SIN pero su respuesta se perdio por
     * timeout, el reintento manda el mismo CUF y vuelve rechazado como
     * duplicado: observarla le avisaria al cliente que se rechazo una factura
     * que el SIN tiene validada. Por eso, antes de observar, se le pregunta al
     * SIN el estado de ESE CUF, igual que hace el sistema que factura en
     * produccion (ventas) con las que quedan colgadas por timeout.
     */
    private function resolverRechazo(
        Factura $factura,
        RespuestaSiat $rechazo,
        FabricaServicios $fabrica,
        string $cufd,
        string $cuis,
    ): void {
        try {
            $estado = RespuestaSiat::desde(
                $fabrica->facturacion($factura->empresa, $factura->version)->verificarEstado($factura, $cufd, $cuis),
            );
        } catch (SiatException) {
            // Sin saber si el SIN la tiene no se observa ni se deriva a
            // contingencia (podria estar ya del otro lado).
            $this->reintentarMasTarde();

            return;
        }

        if (in_array($estado->codigoEstado, VerificarEstadoFactura::CODIGOS_VALIDADA, true)) {
            $this->marcarValidada($factura, $estado);

            return;
        }

        // La tiene pero todavia no la proceso: se sigue como una recepcion normal.
        if ($estado->codigoEstado === VerificarEstadoFactura::CODIGO_PENDIENTE) {
            $this->marcarRecibida($factura, $estado->codigoRecepcion);

            return;
        }

        // Excepcion interna del SIN (-1) al recibirla o al consultarla: el SIN
        // no juzgo el documento. Observarla le diria al cliente que su factura
        // se rechazo por una falla del SIN; se reenvia cuando se recupere.
        if (in_array(VerificarEstadoFactura::CODIGO_ERROR_SIN, [$rechazo->codigoEstado, $estado->codigoEstado], true)) {
            $this->reintentarMasTarde();

            return;
        }

        // El SIN no la conoce o la rechazo: el motivo que vale es el de la recepcion.
        $this->observar($factura, $rechazo);
    }

    /**
     * Deja la factura PENDIENTE para otro intento con el backoff del job.
     * Agotados los intentos no se reencola (terminaria en failed_jobs): la
     * retoma la tarea programada 'reintentar-pendientes'.
     */
    private function reintentarMasTarde(): void
    {
        if ($this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);
        }
    }

    private function marcarValidada(Factura $factura, RespuestaSiat $respuesta): void
    {
        $factura->update([
            'estado' => Factura::ESTADO_VALIDADA,
            'enviada_en' => now(),
            'validada_en' => now(),
            'codigo_recepcion' => $respuesta->codigoRecepcion ?? $factura->codigo_recepcion,
            'codigo_estado_siat' => $respuesta->codigoEstado,
        ]);

        NotificarWebhook::dispatch($factura->id, 'factura.validada');
    }

    /**
     * El SIN acuso recibo de ESTA factura sin validarla todavia: pasa a
     * RECIBIDA. ENVIADA queda para las que viajan dentro de un paquete de
     * contingencia, donde el acuse es del paquete y no de cada factura.
     */
    private function marcarRecibida(Factura $factura, ?string $codigoRecepcion): void
    {
        $factura->update([
            'estado' => Factura::ESTADO_RECIBIDA,
            'enviada_en' => now(),
            'codigo_recepcion' => $codigoRecepcion ?? $factura->codigo_recepcion,
        ]);

        // Se consulta el estado final (validada/observada) por separado.
        VerificarEstadoFactura::dispatch($factura->id)->delay(now()->addSeconds(15));
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
            // Queda en la factura y viaja en el webhook: el cliente necesita
            // saber QUE corregir, no solo que fue rechazada.
            'motivo_observacion' => $respuesta->motivo(),
        ]);

        Log::warning('El SIN rechazo la recepcion de la factura.', [
            'factura_id' => $factura->id,
            'cuf' => $factura->cuf,
            'motivo' => $respuesta->motivo(),
        ]);

        NotificarWebhook::dispatch($factura->id, 'factura.observada');
    }
}
