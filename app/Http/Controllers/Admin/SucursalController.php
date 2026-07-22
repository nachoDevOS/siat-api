<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Alta de sucursales de una empresa. El sistema NO las crea en el SIAT: las
 * copia del registro de la Oficina Virtual para poder referenciarlas.
 */
class SucursalController extends Controller
{
    public function store(Request $request, Empresa $empresa): RedirectResponse
    {
        $datos = $request->validate([
            'codigo_sucursal' => ['required', 'integer', 'min:0'],
            'nombre' => ['required', 'string', 'max:255'],
            'municipio' => ['nullable', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:50'],
        ]);

        $empresa->sucursales()->create($datos);

        return redirect()
            ->route('admin.empresas.show', $empresa)
            ->with('estado', 'Sucursal registrada.');
    }
}
