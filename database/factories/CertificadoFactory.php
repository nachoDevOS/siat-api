<?php

namespace Database\Factories;

use App\Models\Certificado;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Certificado>
 */
class CertificadoFactory extends Factory
{
    /**
     * Passphrase del .p12 autofirmado que usa el estado firmable().
     */
    private const PASSPHRASE_PRUEBA = 'secreto';

    /**
     * .p12 autofirmado generado una sola vez por corrida. Generar un par RSA
     * cuesta decimas de segundo, y la emision de una factura ahora exige un
     * certificado que se pueda abrir de verdad: sin cachearlo, cada test que
     * emite pagaria ese costo.
     */
    private static ?string $p12EnCache = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            // Contenido de relleno; el .p12 real se carga en el panel.
            'contenido_p12' => base64_encode(Str::random(64)),
            'passphrase' => Str::random(12),
            'emitido_por' => 'Entidad Certificadora de Prueba',
            'vence_el' => now()->addYear(),
            'activo' => true,
        ];
    }

    /**
     * Certificado que FirmadorXml puede abrir y usar de verdad. Lo necesita
     * todo test que emita una factura, porque la modalidad electronica no
     * admite un documento sin firmar.
     */
    public function firmable(): static
    {
        return $this->state(fn (): array => [
            'contenido_p12' => base64_encode(self::p12DePrueba()),
            'passphrase' => self::PASSPHRASE_PRUEBA,
        ]);
    }

    /**
     * Bytes del .p12 de prueba y su passphrase, para los tests que necesitan
     * subir un certificado real por el panel en vez de sembrarlo en la base.
     */
    public static function bytesP12Prueba(): string
    {
        return self::p12DePrueba();
    }

    public static function passphraseP12Prueba(): string
    {
        return self::PASSPHRASE_PRUEBA;
    }

    /**
     * Genera (una vez) un .p12 autofirmado valido.
     */
    private static function p12DePrueba(): string
    {
        if (self::$p12EnCache !== null) {
            return self::$p12EnCache;
        }

        // Mismo openssl.cnf que GeneradorCertificadoPrueba: sin el, en Windows
        // no se genera ni la clave.
        $opciones = ['config' => config('siat.openssl_config')];

        $clave = openssl_pkey_new($opciones + ['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'certificado-de-prueba'], $clave, $opciones);
        $x509 = openssl_csr_sign($csr, null, $clave, 365, $opciones);

        openssl_pkcs12_export($x509, $p12, $clave, self::PASSPHRASE_PRUEBA);

        return self::$p12EnCache = $p12;
    }
}
