<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\SiatException;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Services\Factura\CargadorCertificado;
use App\Services\Factura\GeneradorCertificadoPrueba;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Carga del certificado digital .p12 de una empresa. El contenido y la
 * passphrase se guardan cifrados (cast 'encrypted' del modelo).
 */
class CertificadoController extends Controller
{
    public function store(Request $request, Empresa $empresa, CargadorCertificado $cargador): RedirectResponse
    {
        $datos = $request->validate([
            'archivo' => ['required', 'file', 'max:5120'],
            'passphrase' => ['required', 'string'],
            'emitido_por' => ['nullable', 'string', 'max:255'],
            'vence_el' => ['nullable', 'date'],
        ]);

        $cargador->cargar(
            $empresa,
            (string) file_get_contents($datos['archivo']->getRealPath()),
            $datos['passphrase'],
            $datos['emitido_por'] ?? null,
            $datos['vence_el'] ?? null,
        );

        return redirect()
            ->route('admin.empresas.show', $empresa)
            ->with('estado', 'Certificado verificado, cargado y activado.');
    }

    /**
     * Genera un certificado autofirmado para el piloto y lo deja activo.
     */
    public function generarPrueba(Empresa $empresa, GeneradorCertificadoPrueba $generador): RedirectResponse
    {
        try {
            $certificado = $generador->generar($empresa);
        } catch (SiatException $e) {
            return redirect()->route('admin.empresas.show', $empresa)->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.empresas.show', $empresa)
            ->with('estado', 'Certificado de prueba generado y activado (vence el '.$certificado->vence_el->format('d/m/Y').'). Solo sirve para el piloto.');
    }
}
