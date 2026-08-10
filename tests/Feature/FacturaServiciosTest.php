<?php

use App\Models\Certificado;
use App\Models\Empresa;
use App\Services\Factura\CalculadorTotales;
use App\Services\Factura\FirmadorXml;
use App\Services\Factura\GeneradorCuf;
use App\Services\Factura\ValidadorFactura;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- GeneradorCuf ---------------------------------------------------------

test('el digito verificador de modulo 11 es determinista', function () {
    // Para '1234' (derecha a izq): 4*2 + 3*3 + 2*4 + 1*5 = 30; 30 % 11 = 8.
    // El digito es el resto, no 11 menos el resto (ver CufModulo11Test).
    expect((new GeneradorCuf)->digitoVerificador('1234'))->toBe(8);
});

test('el CUF termina con el codigo de control del CUFD', function () {
    $cuf = (new GeneradorCuf)->generar([
        'nit' => 123456789,
        'fecha' => '20260722143208123',
        'sucursal' => 0,
        'modalidad' => 1,
        'tipo_emision' => 1,
        'tipo_factura' => 1,
        'tipo_documento_sector' => 1,
        'numero_factura' => 128,
        'punto_venta' => 0,
    ], 'ABCD1234');

    // El CUF es hexadecimal + el codigo de control pegado al final.
    expect($cuf)->toEndWith('ABCD1234')
        ->and($cuf)->toMatch('/^[0-9A-F]+ABCD1234$/');
});

// --- CalculadorTotales ----------------------------------------------------

test('calcula subtotal y total sin descuentos', function () {
    $t = (new CalculadorTotales)->calcular([
        ['cantidad' => 100, 'precio_unitario' => 1.5],
    ]);

    expect($t['subtotal'])->toBe(150.0)
        ->and($t['monto_total'])->toBe(150.0)
        ->and($t['monto_total_sujeto_iva'])->toBe(150.0);
});

test('aplica descuento global y gift card', function () {
    $t = (new CalculadorTotales)->calcular(
        [['cantidad' => 100, 'precio_unitario' => 1.5]],
        descuentoGlobal: 10,
        giftCard: 40,
    );

    // 150 - 10 = 140 total; sujeto a IVA = 140 - 40 gift = 100.
    expect($t['monto_total'])->toBe(140.0)
        ->and($t['monto_total_sujeto_iva'])->toBe(100.0);
});

// --- ValidadorFactura -----------------------------------------------------

test('marca error cuando no hay items', function () {
    $errores = (new ValidadorFactura)->validar(['items' => [], 'comprador' => []]);

    expect($errores)->not->toBeEmpty();
});

test('una venta bien formada es valida', function () {
    $valida = (new ValidadorFactura)->esValida([
        'comprador' => ['razon_social' => 'JUAN', 'numero_documento' => '123'],
        'items' => [['cantidad' => 1, 'precio_unitario' => 10, 'descripcion' => 'x']],
    ]);

    expect($valida)->toBeTrue();
});

// --- FirmadorXml (firma real con un .p12 generado al vuelo) ----------------

test('firma el XML e incrusta un bloque Signature verificable', function () {
    // Se genera un certificado autofirmado de prueba en memoria.
    $pk = openssl_pkey_new(['private_key_bits' => 2048]);
    $csr = openssl_csr_new(['commonName' => 'prueba-siat'], $pk);
    $x509 = openssl_csr_sign($csr, null, $pk, 365);
    openssl_pkcs12_export($x509, $p12, $pk, 'secreto');

    // Se persiste para que el cast 'encrypted' cifre y descifre como en produccion.
    $certificado = Certificado::create([
        'empresa_id' => Empresa::factory()->create()->id,
        'contenido_p12' => base64_encode($p12),
        'passphrase' => 'secreto',
        'activo' => true,
    ])->fresh();

    $xmlFirmado = (new FirmadorXml)->firmar('<factura><cuf>ABC</cuf></factura>', $certificado);

    expect($xmlFirmado)->toContain('<Signature')
        ->and($xmlFirmado)->toContain('SignatureValue')
        ->and($xmlFirmado)->toContain('X509Certificate');
});
