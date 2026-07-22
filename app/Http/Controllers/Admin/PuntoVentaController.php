<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sucursal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Alta de puntos de venta desde el panel. El registro en el SIAT
 * (registroPuntoVenta + CUIS + primer CUFD) es un flujo aparte; aca se crea el
 * registro local. Ver seccion 8.5 del documento maestro.
 */
class PuntoVentaController extends Controller
{
    public function store(Request $request, Sucursal $sucursal): RedirectResponse
    {
        $datos = $request->validate([
            'codigo_punto_venta' => ['required', 'integer', 'min:0'],
            'nombre' => ['required', 'string', 'max:255'],
            'tipo_punto_venta' => ['required', 'integer'],
        ]);

        $sucursal->puntosVenta()->create($datos + ['siguiente_factura' => 1, 'activo' => true]);

        return redirect()
            ->route('admin.empresas.show', $sucursal->empresa_id)
            ->with('estado', 'Punto de venta creado.');
    }
}
