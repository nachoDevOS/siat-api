<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Carga del certificado digital .p12 de una empresa. El contenido y la
 * passphrase se guardan cifrados (cast 'encrypted' del modelo).
 */
class CertificadoController extends Controller
{
    public function store(Request $request, Empresa $empresa): RedirectResponse
    {
        $datos = $request->validate([
            'archivo' => ['required', 'file', 'max:5120'],
            'passphrase' => ['required', 'string'],
            'emitido_por' => ['nullable', 'string', 'max:255'],
            'vence_el' => ['nullable', 'date'],
        ]);

        // Solo un certificado activo por empresa: se desactivan los anteriores.
        $empresa->certificados()->update(['activo' => false]);

        $empresa->certificados()->create([
            // El .p12 se guarda en base64 y el cast lo cifra en reposo.
            'contenido_p12' => base64_encode(file_get_contents($datos['archivo']->getRealPath())),
            'passphrase' => $datos['passphrase'],
            'emitido_por' => $datos['emitido_por'] ?? null,
            'vence_el' => $datos['vence_el'] ?? null,
            'activo' => true,
        ]);

        return redirect()
            ->route('admin.empresas.show', $empresa)
            ->with('estado', 'Certificado cargado y activado.');
    }
}
