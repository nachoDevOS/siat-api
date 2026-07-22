<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Factura;
use App\Models\LogSiat;
use Illuminate\View\View;

/**
 * Monitor de la operacion: ultimas peticiones SOAP y conteo de facturas por
 * estado. Es la evidencia rapida de que el sistema esta hablando con el SIAT.
 */
class MonitorController extends Controller
{
    public function index(): View
    {
        $logs = LogSiat::with('empresa')->latest('created_at')->limit(50)->get();

        // Conteo de facturas por estado para el tablero.
        $porEstado = Factura::query()
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        return view('admin.monitor.index', compact('logs', 'porEstado'));
    }
}
