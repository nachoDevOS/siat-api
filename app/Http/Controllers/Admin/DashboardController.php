<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CasoPrueba;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\LogSiat;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use Illuminate\View\View;

/**
 * Tablero principal del panel /admin. Reune los indicadores de la operacion:
 * empresas, facturas por estado, puntos de venta y salud de las peticiones SOAP
 * al SIAT. Es la primera pantalla que ve el administrador al entrar.
 */
class DashboardController extends Controller
{
    public function index(): View
    {
        $facturasPorEstado = Factura::query()
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        $totalPeticiones = LogSiat::count();
        $peticionesExitosas = LogSiat::where('exitoso', true)->count();
        $promedioMs = (int) round((float) LogSiat::avg('duracion_ms'));
        $tasaExito = $totalPeticiones > 0
            ? (int) round($peticionesExitosas / $totalPeticiones * 100)
            : 0;

        return view('admin.dashboard.index', [
            'totalEmpresas' => Empresa::count(),
            'empresasProduccion' => Empresa::where('codigo_ambiente', 1)->count(),
            'empresasPiloto' => Empresa::where('codigo_ambiente', 2)->count(),
            'totalFacturas' => $facturasPorEstado->sum(),
            'totalSucursales' => Sucursal::count(),
            'totalPuntosVenta' => PuntoVenta::count(),
            'totalCasosPrueba' => CasoPrueba::count(),
            'facturasPorEstado' => $facturasPorEstado,
            'totalPeticiones' => $totalPeticiones,
            'tasaExito' => $tasaExito,
            'promedioMs' => $promedioMs,
            'ultimasFacturas' => Factura::with('empresa')->latest()->limit(6)->get(),
            'ultimasPeticiones' => LogSiat::with('empresa')->latest('created_at')->limit(8)->get(),
        ]);
    }
}
