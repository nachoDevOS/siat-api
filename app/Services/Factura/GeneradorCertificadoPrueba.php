<?php

namespace App\Services\Factura;

use App\Exceptions\SiatException;
use App\Models\Certificado;
use App\Models\Empresa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Genera un certificado .p12 AUTOFIRMADO para correr el piloto sin esperar el
 * de una entidad certificadora (ADSIB, Digicert).
 *
 * Solo para el ambiente de pruebas: no tiene validez legal y una factura de
 * produccion firmada con el es nula. Por eso se rechaza en una empresa de
 * produccion, queda marcado con un emisor propio (Certificado::esDePrueba) y
 * EmisorFactura se niega a firmar con el fuera del piloto.
 */
class GeneradorCertificadoPrueba
{
    /** Vigencia del certificado generado. */
    private const DIAS_VIGENCIA = 365;

    /**
     * Genera el certificado, lo guarda cifrado y lo deja como el activo de la
     * empresa (desactivando el anterior, igual que al subir uno).
     *
     * @throws SiatException si la empresa no esta en el ambiente de pruebas.
     */
    public function generar(Empresa $empresa): Certificado
    {
        if ($empresa->codigo_ambiente !== config('siat.codigos.ambiente.piloto')) {
            throw new SiatException('Solo se puede generar un certificado de prueba para una empresa en el ambiente de pruebas (2).');
        }

        $clave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        // El titular es la empresa (razon social y NIT), como en un certificado
        // real; la organizacion avisa que es de prueba a quien lo inspeccione.
        $solicitud = openssl_csr_new([
            'commonName' => mb_substr((string) $empresa->razon_social, 0, 64),
            'serialNumber' => (string) $empresa->nit,
            'organizationName' => 'AUTOFIRMADO - SOLO PRUEBAS',
            'countryName' => 'BO',
        ], $clave, ['digest_alg' => 'sha256']);

        $x509 = openssl_csr_sign($solicitud, null, $clave, self::DIAS_VIGENCIA, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));

        // La passphrase la elige el sistema: nadie la tiene que tipear, solo
        // protege el .p12 dentro de la base (que ademas va cifrado).
        $passphrase = Str::random(32);

        if ($x509 === false || ! openssl_pkcs12_export($x509, $p12, $clave, $passphrase)) {
            throw new SiatException('OpenSSL no pudo generar el certificado de prueba.');
        }

        return DB::transaction(function () use ($empresa, $p12, $passphrase): Certificado {
            $empresa->certificados()->update(['activo' => false]);

            return $empresa->certificados()->create([
                'contenido_p12' => base64_encode($p12),
                'passphrase' => $passphrase,
                'emitido_por' => Certificado::EMISOR_AUTOFIRMADO,
                'vence_el' => now()->addDays(self::DIAS_VIGENCIA),
                'activo' => true,
            ]);
        });
    }
}
