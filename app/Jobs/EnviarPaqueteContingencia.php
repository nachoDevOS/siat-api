<?php

namespace App\Jobs;

use App\Exceptions\SiatException;
use App\Models\Factura;
use App\Models\Paquete;
use App\Services\Contingencia\ArmadorPaquete;
use App\Services\Siat\ServicioFacturacion;
use App\Services\Siat\SiatClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

    public function handle(ArmadorPaquete $armador): void
    {
        $paquete = Paquete::with('empresa')->find($this->paqueteId);

        if ($paquete === null || $paquete->estado === 'ENVIADO') {
            return;
        }

        $xml = $armador->armar($paquete);

        try {
            $servicio = new ServicioFacturacion($paquete->empresa, new SiatClient($paquete->empresa));
            $respuesta = $servicio->recepcionarPaquete([
                'codigoPuntoVenta' => $paquete->punto_venta_id,
            ], $xml);

            $paquete->update([
                'estado' => 'ENVIADO',
                'enviado_en' => now(),
                'codigo_recepcion' => (string) data_get($respuesta, 'RespuestaServicioFacturacion.codigoRecepcion'),
            ]);

            // Las facturas del paquete pasan de contingencia a enviadas.
            Factura::where('punto_venta_id', $paquete->punto_venta_id)
                ->where('estado', Factura::ESTADO_CONTINGENCIA)
                ->update(['estado' => Factura::ESTADO_ENVIADA, 'enviada_en' => now()]);
        } catch (SiatException) {
            // El SIAT sigue caido: se reintenta con el backoff del job.
            $this->release(300);
        }
    }
}
