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

        if ($puntoVenta === null) {
            return;
        }

        // Sin CUIS vigente no se puede pedir CUFD; eso lo resuelve otro flujo,
        // asi que no es una falla de este job.
        if ($puntoVenta->cuisVigente() === null) {
            return;
        }

        // El gestor valida que el SIN haya devuelto codigo y codigo de control:
        // si no, lanza y el job falla en vez de guardar un CUFD vacio que
        // envenenaria el CUF de todas las facturas del punto de venta.
        $gestor->solicitarCufd($puntoVenta);
    }
}
