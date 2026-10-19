<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Carga del CAFC que el SIN entrego a una empresa (codigo y rango).
 *
 * Como el certificado: uno solo activo por empresa. Cargar otro (por ejemplo,
 * cuando se agota el rango) desactiva el anterior, que queda como historial.
 */
class CafcController extends Controller
{
    public function store(Request $request, Empresa $empresa): RedirectResponse
    {
        $datos = $request->validate([
            // Mismo codigo dos veces seria el mismo rango contado dos veces.
            'codigo' => ['required', 'string', 'max:50', 'alpha_num',
                Rule::unique('cafcs')->where('empresa_id', $empresa->id)],
            'numero_inicio' => ['required', 'integer', 'min:1'],
            'numero_fin' => ['required', 'integer', 'gte:numero_inicio'],
        ], [
            'codigo.unique' => 'Ese CAFC ya esta cargado para esta empresa.',
            'numero_fin.gte' => 'El fin del rango no puede ser menor que el inicio.',
        ]);

        DB::transaction(function () use ($empresa, $datos) {
            $empresa->cafcs()->update(['activo' => false]);

            $empresa->cafcs()->create([
                // El SIN lo escribe en mayusculas; asi se compara siempre igual.
                'codigo' => strtoupper($datos['codigo']),
                'numero_inicio' => $datos['numero_inicio'],
                'numero_fin' => $datos['numero_fin'],
                'activo' => true,
            ]);
        });

        return redirect()
            ->route('admin.empresas.show', $empresa)
            ->with('estado', 'CAFC cargado y activado.');
    }
}
