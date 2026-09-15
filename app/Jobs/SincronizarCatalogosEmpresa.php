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

        // Cualquier punto de venta de la empresa con CUIS vigente sirve, pero
        // hay que pasar el PUNTO DE VENTA entero: el SIN valida que el CUIS
        // corresponda a la sucursal y al codigo que viajan en la peticion.
        $puntoVenta = PuntoVenta::with('sucursal.empresa')
            ->whereHas('sucursal', fn ($q) => $q->where('empresa_id', $empresa->id))
            ->get()
            ->first(fn (PuntoVenta $pv) => $pv->cuisVigente() !== null);

        if ($puntoVenta === null) {
            return;
        }

        $sincronizador->sincronizarTodo($puntoVenta);
    }
}
