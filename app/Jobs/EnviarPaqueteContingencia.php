<?php

namespace App\Jobs;

use App\Exceptions\SiatException;
use App\Models\Factura;
use App\Models\Paquete;
use App\Services\Contingencia\ArmadorPaquete;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\RespuestaSiat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Envia un paquete de facturas de contingencia al SIAT (recepcionPaqueteFactura)
 * y marca las facturas como enviadas.
 */
class EnviarPaqueteContingencia implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $paqueteId) {}

    public function handle(ArmadorPaquete $armador, FabricaServicios $fabrica): void
    {
        $paquete = Paquete::with(['empresa', 'puntoVenta.sucursal'])->find($this->paqueteId);

        if ($paquete === null || $paquete->estado === 'ENVIADO') {
            return;
        }

        $xml = $armador->armar($paquete);

        try {
            // Codigos del SIN, no ids internos de nuestras tablas.
            $respuesta = RespuestaSiat::desde(
                $fabrica->facturacion($paquete->empresa)->recepcionarPaquete([
                    'codigoSucursal' => $paquete->puntoVenta->sucursal->codigo_sucursal,
                    'codigoPuntoVenta' => $paquete->puntoVenta->codigo_punto_venta,
                ], $xml),
            );

            // Rechazo del SIN sin SoapFault: el paquete queda PENDIENTE y sus
            // facturas en contingencia (siguen siendo validas). Se propaga para
            // que la falla y su motivo queden en failed_jobs: reintentar el
            // mismo paquete no lo va a arreglar, hay que corregirlo antes.
            if (! $respuesta->aceptada) {
                Log::warning('El SIN rechazo el paquete de contingencia.', [
                    'paquete_id' => $paquete->id,
                    'motivo' => $respuesta->motivo(),
                ]);

                throw new SiatException("El SIN rechazo el paquete: {$respuesta->motivo()}");
            }

            $paquete->update([
                'estado' => 'ENVIADO',
                'enviado_en' => now(),
                'codigo_recepcion' => $respuesta->codigoRecepcion,
            ]);

            // Solo las facturas de ESTE paquete pasan de contingencia a enviadas.
            Factura::where('paquete_id', $paquete->id)
                ->where('estado', Factura::ESTADO_CONTINGENCIA)
                ->update(['estado' => Factura::ESTADO_ENVIADA, 'enviada_en' => now()]);
        } catch (SiatException $e) {
            // Mismo criterio que EnviarFacturaAlSiat: se respeta el backoff
            // declarado del job en vez de un release fijo, que lo contradecia.
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 900);

                return;
            }

            // Agotados los reintentos, el paquete sigue en PENDIENTE y sus
            // facturas en contingencia: siguen siendo validas. La falla se
            // propaga para que quede en failed_jobs y se pueda reenviar.
            throw $e;
        }
    }
}
