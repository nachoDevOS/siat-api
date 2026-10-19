<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sitio\SolicitudContacto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Bandeja del formulario de contacto del sitio publico.
 */
class SolicitudContactoController extends Controller
{
    public function index(Request $request): View
    {
        $pendientes = $request->query('ver') !== 'todas';

        return view('admin.solicitudes.index', [
            'solicitudes' => SolicitudContacto::query()
                ->when($pendientes, fn ($q) => $q->whereNull('atendida_en'))
                ->latest()
                ->paginate(30)
                ->withQueryString(),
            'soloPendientes' => $pendientes,
        ]);
    }

    /**
     * Marca o desmarca la solicitud como atendida.
     */
    public function atender(SolicitudContacto $solicitud): RedirectResponse
    {
        $solicitud->update(['atendida_en' => $solicitud->estaAtendida() ? null : now()]);

        return back()->with('estado', $solicitud->estaAtendida() ? 'Solicitud marcada como atendida.' : 'Solicitud vuelta a pendiente.');
    }
}
