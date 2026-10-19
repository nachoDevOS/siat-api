<?php

namespace App\Services\Factura;

use App\Models\Certificado;
use App\Models\Empresa;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Carga el certificado digital .p12 de una empresa y lo deja activo. Lo usan
 * el panel y la API (autogestion del cliente) con las mismas reglas.
 *
 * El contenido y la passphrase se guardan cifrados (cast 'encrypted').
 */
class CargadorCertificado
{
    /**
     * @throws ValidationException si el .p12 no abre o no trae clave privada:
     *                             el certificado anterior sigue activo.
     */
    public function cargar(Empresa $empresa, string $binario, string $passphrase, ?string $emitidoPor = null, ?string $venceEl = null): Certificado
    {
        // Se abre ANTES de guardar nada. Sin esto, un .p12 corrupto o una
        // passphrase mal tipeada se guardaban igual y desactivaban el
        // certificado que si funcionaba: el panel confirmaba "cargado y
        // activado" y el cliente dejaba de facturar en silencio, porque la
        // falla recien aparecia al firmar, dentro de un job en segundo plano.
        $almacen = $this->abrirOFallar($binario, $passphrase);

        // Solo un certificado activo por empresa: se desactivan los anteriores.
        // Recien aca, con el nuevo ya comprobado.
        $empresa->certificados()->update(['activo' => false]);

        return $empresa->certificados()->create([
            // El .p12 se guarda en base64 y el cast lo cifra en reposo.
            'contenido_p12' => base64_encode($binario),
            'passphrase' => $passphrase,
            'emitido_por' => $emitidoPor,
            // La fecha la trae el propio certificado; lo que escriba el
            // operador solo se usa si el certificado no la declara. Antes era
            // al reves y, si no la tipeaba, el aviso de vencimiento a 30 dias
            // no disparaba nunca (filtra por 'vence_el' no nulo).
            'vence_el' => $this->vencimientoDe($almacen) ?? $venceEl,
            'activo' => true,
        ]);
    }

    /**
     * Abre el .p12 con su passphrase o corta con un error de validacion.
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
        return Certificado::vencimientoDeX509((string) ($almacen['cert'] ?? ''));
    }
}
