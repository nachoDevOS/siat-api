<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

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

        $binario = (string) file_get_contents($datos['archivo']->getRealPath());

        // Se abre ANTES de guardar nada. Sin esto, un .p12 corrupto o una
        // passphrase mal tipeada se guardaban igual y desactivaban el
        // certificado que si funcionaba: el panel confirmaba "cargado y
        // activado" y el cliente dejaba de facturar en silencio, porque la
        // falla recien aparecia al firmar, dentro de un job en segundo plano.
        $almacen = $this->abrirOFallar($binario, $datos['passphrase']);

        // Solo un certificado activo por empresa: se desactivan los anteriores.
        // Recien aca, con el nuevo ya comprobado.
        $empresa->certificados()->update(['activo' => false]);

        $empresa->certificados()->create([
            // El .p12 se guarda en base64 y el cast lo cifra en reposo.
            'contenido_p12' => base64_encode($binario),
            'passphrase' => $datos['passphrase'],
            'emitido_por' => $datos['emitido_por'] ?? null,
            // La fecha la trae el propio certificado; lo que escriba el
            // operador solo se usa si el certificado no la declara. Antes era
            // al reves y, si no la tipeaba, el aviso de vencimiento a 30 dias
            // no disparaba nunca (filtra por 'vence_el' no nulo).
            'vence_el' => $this->vencimientoDe($almacen) ?? $datos['vence_el'] ?? null,
            'activo' => true,
        ]);

        return redirect()
            ->route('admin.empresas.show', $empresa)
            ->with('estado', 'Certificado verificado, cargado y activado.');
    }

    /**
     * Abre el .p12 con su passphrase o corta con un error de validacion visible
     * en el formulario.
     *
     * @return array<string, mixed> almacen devuelto por openssl_pkcs12_read
     *
     * @throws ValidationException
     */
    private function abrirOFallar(string $binario, string $passphrase): array
    {
        $almacen = [];

        if (! openssl_pkcs12_read($binario, $almacen, $passphrase)) {
            throw ValidationException::withMessages([
                'archivo' => 'No se pudo abrir el certificado: el archivo no es un .p12 valido o la contrasena no corresponde. El certificado anterior sigue activo.',
            ]);
        }

        if (blank($almacen['pkey'] ?? null)) {
            throw ValidationException::withMessages([
                'archivo' => 'El certificado no contiene una clave privada: no sirve para firmar.',
            ]);
        }

        return $almacen;
    }

    /**
     * Fecha de vencimiento declarada por el propio certificado X509.
     *
     * @param  array<string, mixed>  $almacen
     */
    private function vencimientoDe(array $almacen): ?Carbon
    {
        $datos = openssl_x509_parse((string) ($almacen['cert'] ?? ''));

        if ($datos === false || ! isset($datos['validTo_time_t'])) {
            return null;
        }

        return Carbon::createFromTimestamp($datos['validTo_time_t']);
    }
}
