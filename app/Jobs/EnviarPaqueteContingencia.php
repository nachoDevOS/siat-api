<?php

namespace App\Jobs;

use App\Exceptions\SiatException;
use App\Models\Factura;
use App\Models\Paquete;
use App\Services\Contingencia\ArmadorPaquete;
use App\Services\Siat\FabricaServicios;
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

    public function handle(ArmadorPaquete $armador, FabricaServicios $fabrica): void
    {
        $paquete = Paquete::with(['empresa', 'puntoVenta.sucursal'])->find($this->paqueteId);

        if ($paquete === null || $paquete->estado === 'ENVIADO') {
            return;
        }

        $xml = $armador->armar($paquete);

        try {
            // Codigos del SIN, no ids internos de nuestras tablas.
            $respuesta = $fabrica->facturacion($paquete->empresa)->recepcionarPaquete([
                'codigoSucursal' => $paquete->puntoVenta->sucursal->codigo_sucursal,
                'codigoPuntoVenta' => $paquete->puntoVenta->codigo_punto_venta,
            ], $xml);

            $paquete->update([
                'estado' => 'ENVIADO',
                'enviado_en' => now(),
                'codigo_recepcion' => (string) data_get($respuesta, 'RespuestaServicioFacturacion.codigoRecepcion'),
            ]);

            // Solo las facturas de ESTE paquete pasan de contingencia a enviadas.
            Factura::where('paquete_id', $paquete->id)
                ->where('estado', Factura::ESTADO_CONTINGENCIA)
                ->update(['estado' => Factura::ESTADO_ENVIADA, 'enviada_en' => now()]);
        } catch (SiatException) {
            // El SIAT sigue caido: se reintenta con el backoff del job.
            $this->release(300);
        }
    }
}
