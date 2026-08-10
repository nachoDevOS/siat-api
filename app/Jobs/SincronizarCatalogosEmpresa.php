<?php

namespace App\Jobs;

use App\Models\Empresa;
use App\Models\PuntoVenta;
use App\Services\Catalogos\SincronizadorEmpresa;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sincroniza los catalogos por empresa (actividades, productos, leyendas).
 * Se dispara al alta del cliente y semanalmente por cron.
 */
class SincronizarCatalogosEmpresa implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $empresaId) {}

    public function handle(SincronizadorEmpresa $sincronizador): void
    {
        $empresa = Empresa::find($this->empresaId);

        if ($empresa === null) {
            return;
        }

        // Cualquier punto de venta activo de la empresa sirve para tomar el CUIS.
        $cuis = PuntoVenta::query()
            ->whereHas('sucursal', fn ($q) => $q->where('empresa_id', $empresa->id))
            ->get()
            ->map(fn (PuntoVenta $pv) => $pv->cuisVigente())
            ->filter()
            ->first();

        if ($cuis === null) {
            return;
        }

        $sincronizador->sincronizarTodo($empresa, $cuis->codigo);
    }
}
