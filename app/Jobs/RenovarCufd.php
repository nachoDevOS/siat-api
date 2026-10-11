<?php

namespace App\Jobs;

use App\Models\PuntoVenta;
use App\Services\Siat\GestorCodigos;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
}
