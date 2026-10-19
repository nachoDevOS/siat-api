<?php

namespace App\Jobs;

use App\Models\PuntoVenta;
use App\Services\Siat\GestorCodigos;
use App\Services\Webhooks\Notificador;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Solicita un CUFD nuevo para un punto de venta y lo guarda como historial.
 * Lo usan tanto el cron preventivo (cada hora) como la capa reactiva al emitir.
 */
class RenovarCufd implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $puntoVentaId) {}

    public function handle(GestorCodigos $gestor): void
    {
        $puntoVenta = PuntoVenta::with('sucursal.empresa')->find($this->puntoVentaId);

        // Solo puntos de venta que el SIN conoce, de empresas con token: con
        // cualquiera de los otros el SIN rechaza y el job fallaria cada hora.
        if ($puntoVenta === null
            || ! $puntoVenta->existeEnElSiat()
            || blank($puntoVenta->sucursal->empresa->token_delegado)) {
            return;
        }

        // Si el CUIS vencio, el gestor lo pide antes: la API maneja los
        // codigos sola. Y valida que el SIN haya devuelto codigo y codigo de
        // control: si no, lanza y el job falla en vez de guardar un CUFD vacio
        // que envenenaria el CUF de todas las facturas del punto de venta.
        $gestor->renovarCufd($puntoVenta);
    }

    /**
     * Agotados los intentos, el punto de venta puede quedarse sin CUFD y la
     * proxima venta tendria que pedirlo en el momento (o fallar). Se avisa al
     * cliente por webhook para que no se entere recien en la caja.
     */
    public function failed(?Throwable $e): void
    {
        $puntoVenta = PuntoVenta::with('sucursal.empresa')->find($this->puntoVentaId);

        if ($puntoVenta === null) {
            return;
        }

        app(Notificador::class)->emitir($puntoVenta->sucursal->empresa, 'cufd.renovacion_fallida', [
            'sucursal' => $puntoVenta->sucursal->codigo_sucursal,
            'punto_venta' => $puntoVenta->codigo_punto_venta,
            'cufd_vigente_hasta' => $puntoVenta->cufdVigente()?->fecha_vigencia?->toIso8601String(),
            'error' => $e?->getMessage(),
        ]);
    }
}
