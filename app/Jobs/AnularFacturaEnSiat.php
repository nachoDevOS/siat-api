<?php

namespace App\Jobs;

use App\Exceptions\SiatException;
use App\Models\Factura;
use App\Models\FacturaAnulada;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\RespuestaSiat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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
            $respuesta = RespuestaSiat::desde(
                $fabrica->facturacion($factura->empresa)
                    ->anular($factura, (int) $anulacion->motivo, (string) $cufd),
            );
        } catch (SiatException $e) {
            // El SIAT no responde. Se reintenta respetando el backoff declarado
            // del job; agotados los intentos la falla queda en failed_jobs con
            // la anulacion todavia PENDIENTE, que es lo que refleja la realidad.
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 900);

                return;
            }

            throw $e;
        }

        // El SIN puede rechazar la anulacion (fuera de plazo, motivo invalido,
        // factura que no conoce) sin lanzar SoapFault. Si eso pasa, la factura
        // SIGUE VIGENTE ante el SIN: dejarla ANULADA en local seria mentirle al
        // cliente, asi que se revierte y se registra el motivo.
        if (! $respuesta->aceptada) {
            $this->revertir($factura, $anulacion, $respuesta);

            return;
        }

        $anulacion->update([
            'codigo_recepcion' => $respuesta->codigoRecepcion,
            'estado' => FacturaAnulada::ESTADO_CONFIRMADA,
        ]);

        NotificarWebhook::dispatch($factura->id, 'factura.anulada');
    }

    /**
     * Devuelve la factura al estado que tenia antes de pedir la anulacion y
     * deja escrito por que el SIN la rechazo.
     */
    private function revertir(Factura $factura, FacturaAnulada $anulacion, RespuestaSiat $respuesta): void
    {
        $anulacion->update([
            'estado' => FacturaAnulada::ESTADO_RECHAZADA,
            'motivo_rechazo' => $respuesta->motivo(),
        ]);

        $factura->update([
            'estado' => $anulacion->estado_anterior ?? Factura::ESTADO_VALIDADA,
        ]);

        Log::warning('El SIN rechazo la anulacion de la factura.', [
            'factura_id' => $factura->id,
            'cuf' => $factura->cuf,
            'motivo' => $respuesta->motivo(),
        ]);

        NotificarWebhook::dispatch($factura->id, 'factura.anulacion_rechazada');
    }
}
