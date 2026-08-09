<?php

namespace App\Jobs;

use App\Exceptions\SiatException;
use App\Models\Factura;
use App\Models\FacturaAnulada;
use App\Services\Siat\FabricaServicios;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Transmite al SIAT la anulacion que el cliente ya registro localmente.
 *
 * La anulacion se guarda primero en nuestra base (respuesta inmediata al
 * sistema de ventas) y este job la confirma contra el SIN. Hasta que el SIN
 * devuelva su codigo de recepcion, la anulacion esta registrada pero no
 * confirmada.
 */
class AnularFacturaEnSiat implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 300, 900];

    public function __construct(public readonly int $facturaId) {}

    public function handle(FabricaServicios $fabrica): void
    {
        $factura = Factura::with(['empresa', 'puntoVenta.sucursal', 'cufd'])->find($this->facturaId);

        if ($factura === null) {
            return;
        }

        $anulacion = FacturaAnulada::where('factura_id', $factura->id)->first();

        // Sin registro de anulacion no hay nada que transmitir; y si el SIN ya
        // dio su codigo de recepcion, este job ya cumplio.
        if ($anulacion === null || filled($anulacion->codigo_recepcion)) {
            return;
        }

        $cufd = $factura->cufd?->codigo ?? optional($factura->puntoVenta->cufdVigente())->codigo;

        try {
            $respuesta = $fabrica->facturacion($factura->empresa)
                ->anular($factura, (int) $anulacion->motivo, (string) $cufd);
        } catch (SiatException) {
            // El SIAT no responde: se reintenta con el backoff del job. La
            // factura sigue ANULADA en local, solo falta la confirmacion.
            $this->release(300);

            return;
        }

        $anulacion->update([
            'codigo_recepcion' => (string) data_get($respuesta, 'RespuestaServicioFacturacion.codigoRecepcion'),
        ]);

        NotificarWebhook::dispatch($factura->id, 'factura.anulada');
    }
}
