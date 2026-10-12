<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\Trabajo;
use App\Services\Trabajos\RegistroTrabajos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Pantalla "Trabajos": todo lo que paso por la cola (en cola, en proceso,
 * exitoso, reintentando, fallido), con filtros, el error de cada fallido, sus
 * acciones y el semaforo del worker y del scheduler.
 *
 * Antes un job fallido solo se veia con php artisan queue:failed.
 */
class TrabajoController extends Controller
{
    public function index(Request $request): View
    {
        $filtros = $request->validate([
            'estado' => ['nullable', Rule::in(array_keys(Trabajo::ESTADOS))],
            'clase' => ['nullable', Rule::in(array_keys(Trabajo::NOMBRES))],
            'empresa' => ['nullable', 'integer'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'piloto' => ['nullable', 'boolean'],
            'pausa' => ['nullable', 'boolean'],
        ]);

        $filtros = $this->conMesActualPorDefecto($request, $filtros);

        // Los contadores de las pestanas respetan los demas filtros.
        $base = $this->filtrar(Trabajo::query(), $filtros);
        $conteos = (clone $base)->selectRaw('estado, count(*) as total')->groupBy('estado')->pluck('total', 'estado');

        $trabajos = (clone $base)
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->with('empresa')
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.trabajos.index', [
            'trabajos' => $trabajos,
            'conteos' => $conteos,
            'total' => $conteos->sum(),
            'filtros' => $filtros,
            'empresas' => Empresa::orderBy('nombre_comercial')->get(['id', 'nombre_comercial']),
            'procesos' => RegistroTrabajos::estadoProcesos(),
            // Numero de factura para mostrar en vez del id interno.
            'numerosFactura' => Factura::whereIn('id', $trabajos->where('relacionado_tipo', 'factura')->pluck('relacionado_id'))
                ->pluck('numero_factura', 'id'),
            // Para saber que fallidos todavia se pueden reintentar.
            'enFallidos' => DB::table('failed_jobs')->whereIn('uuid', $trabajos->pluck('uuid'))->pluck('uuid')->flip(),
        ]);
    }

    /**
     * Vuelve a poner en cola un trabajo fallido (lo mismo que queue:retry).
     */
    public function reintentar(Trabajo $trabajo): RedirectResponse
    {
        if ($trabajo->estado !== Trabajo::FALLIDO || ! DB::table('failed_jobs')->where('uuid', $trabajo->uuid)->exists()) {
            return back()->with('error', 'Ese trabajo no está entre los fallidos que se pueden reintentar.');
        }

        Artisan::call('queue:retry', ['id' => [$trabajo->uuid]]);

        return back()->with('estado', "\"{$trabajo->nombre()}\" volvió a la cola.");
    }

    /**
     * Reintenta todos los fallidos que siguen guardados en failed_jobs.
     */
    public function reintentarTodos(): RedirectResponse
    {
        $uuids = DB::table('failed_jobs')
            ->whereIn('uuid', Trabajo::where('estado', Trabajo::FALLIDO)->pluck('uuid'))
            ->pluck('uuid')
            ->all();

        if ($uuids === []) {
            return back()->with('error', 'No hay trabajos fallidos para reintentar.');
        }

        Artisan::call('queue:retry', ['id' => $uuids]);

        return back()->with('estado', count($uuids).' trabajo(s) fallido(s) volvieron a la cola.');
    }

    /**
     * Descarta un fallido: lo quita de failed_jobs y del historial.
     */
    public function descartar(Trabajo $trabajo): RedirectResponse
    {
        abort_unless($trabajo->estado === Trabajo::FALLIDO, 404);

        Artisan::call('queue:forget', ['id' => $trabajo->uuid]);
        $nombre = $trabajo->nombre();
        $trabajo->delete();

        return back()->with('estado', "\"{$nombre}\" descartado.");
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    private function filtrar(Builder $consulta, array $filtros): Builder
    {
        return $consulta
            // El piloto puede encolar cientos: oculto salvo que se pida.
            ->when(! ($filtros['piloto'] ?? false), fn ($q) => $q->where('cola', '!=', 'piloto'))
            ->when($filtros['clase'] ?? null, fn ($q, $clase) => $q->where('clase', $clase))
            ->when($filtros['empresa'] ?? null, fn ($q, $id) => $q->where('empresa_id', $id))
            ->when($filtros['desde'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($filtros['hasta'] ?? null, fn ($q, $h) => $q->whereDate('created_at', '<=', $h));
    }
}
