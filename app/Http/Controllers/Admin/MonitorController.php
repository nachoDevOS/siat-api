<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\LogApi;
use App\Models\LogSiat;
use App\Models\TokenApi;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Monitor de la operacion: conteo de facturas por estado, ultimas peticiones
 * SOAP al SIAT y ultimas llamadas de los sistemas de venta a la API.
 */
class MonitorController extends Controller
{
    public function index(Request $request): View
    {
        $logs = LogSiat::with('empresa')->latest('created_at')->limit(50)->get();

        // Llamadas de los sistemas de venta a la API (logs_api), filtrables
        // por empresa y por las fallidas: un token filtrado o una caja con un
        // bucle se notan aca (401, 403, 429 desde una IP rara).
        $empresaId = $request->integer('empresa') ?: null;
        $soloErrores = $request->boolean('errores');

        $llamadas = LogApi::with('empresa')
            ->when($empresaId, fn ($q) => $q->where('empresa_id', $empresaId))
            ->when($soloErrores, fn ($q) => $q->where('estado_http', '>=', 400))
            ->latest('id')
            ->limit(100)
            ->get();

        // Nombre del token de cada llamada; uno revocado ya no esta y se
        // muestra por su id.
        $tokens = TokenApi::whereIn('id', $llamadas->pluck('token_id')->filter()->unique())->pluck('name', 'id');

        // Conteo de facturas por estado para el tablero.
        $porEstado = Factura::query()
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        return view('admin.monitor.index', [
            'logs' => $logs,
            'porEstado' => $porEstado,
            'llamadas' => $llamadas,
            'tokens' => $tokens,
            'empresas' => Empresa::orderBy('nombre_comercial')->get(['id', 'nombre_comercial']),
            'empresaId' => $empresaId,
            'soloErrores' => $soloErrores,
        ]);
    }
}
