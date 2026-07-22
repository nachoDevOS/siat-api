<?php

namespace App\Jobs;

use App\Exceptions\SiatException;
use App\Models\Factura;
use App\Services\Contingencia\GestorContingencia;
use App\Services\Factura\GeneradorPdf;
use App\Services\Siat\ServicioFacturacion;
use App\Services\Siat\SiatClient;
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

    public function handle(GeneradorPdf $pdf, GestorContingencia $contingencia): void
    {
        $factura = Factura::with(['empresa', 'puntoVenta.sucursal', 'cufd', 'items'])->find($this->facturaId);

        if ($factura === null || $factura->estado === Factura::ESTADO_VALIDADA) {
            return;
        }

        // El PDF se puede generar ya, no depende del SIAT.
        $factura->update(['ruta_pdf' => $pdf->generarYGuardar($factura)]);

        $cufd = $factura->cufd?->codigo ?? optional($factura->puntoVenta->cufdVigente())->codigo;

        try {
            $servicio = new ServicioFacturacion($factura->empresa, new SiatClient($factura->empresa));
            $respuesta = $servicio->recepcionarFactura($factura, (string) $cufd);

            // El codigo de recepcion confirma que el SIN acepto el envio.
            $factura->update([
                'estado' => Factura::ESTADO_ENVIADA,
                'enviada_en' => now(),
                'codigo_recepcion' => (string) data_get($respuesta, 'RespuestaServicioFacturacion.codigoRecepcion'),
            ]);

            // Se consulta el estado final (validada/observada) por separado.
            VerificarEstadoFactura::dispatch($factura->id)->delay(now()->addSeconds(15));
        } catch (SiatException $e) {
            // El SIAT no respondio: la factura pasa a contingencia y sigue valida.
            $contingencia->derivar($factura);
        }
    }
}
