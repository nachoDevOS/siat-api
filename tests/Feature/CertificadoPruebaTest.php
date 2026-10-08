<?php

use App\Exceptions\FacturaInvalidaException;
use App\Models\Certificado;
use App\Models\Cufd;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Factura\EmisorFactura;
use App\Services\Factura\FirmadorXml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('genera un autofirmado a nombre de la empresa, lo activa y sirve para firmar', function () {
    $empresa = Empresa::factory()->create(['razon_social' => 'IGNACIO MOLINA GUZMAN', 'nit' => '7633685015']);
    $anterior = Certificado::factory()->for($empresa)->create();

    $this->post(route('admin.empresas.certificados.prueba', $empresa))
        ->assertRedirect(route('admin.empresas.show', $empresa))
        ->assertSessionHas('estado', fn (string $m) => str_contains($m, 'Solo sirve para el piloto'));

    $certificado = $empresa->certificadoActivo()->sole();
    expect($certificado->esDePrueba())->toBeTrue()
        ->and($anterior->fresh()->activo)->toBeFalse()
        ->and($certificado->vence_el->isFuture())->toBeTrue();

    // El .p12 abre con su passphrase y lleva el titular de la empresa.
    $almacen = [];
    expect(openssl_pkcs12_read(base64_decode($certificado->contenido_p12), $almacen, $certificado->passphrase))->toBeTrue();
    $datos = openssl_x509_parse($almacen['cert']);
    expect($datos['subject']['CN'])->toBe('IGNACIO MOLINA GUZMAN')
        ->and($datos['subject']['serialNumber'])->toBe('7633685015');

    // Y firma un XML con el FirmadorXml real.
    $firmado = app(FirmadorXml::class)->firmar('<?xml version="1.0"?><f><a>1</a></f>', $certificado);
    expect($firmado)->toContain('SignatureValue');
});

test('no se puede generar en una empresa de produccion', function () {
    $empresa = Empresa::factory()->enProduccion()->create();

    $this->post(route('admin.empresas.certificados.prueba', $empresa))
        ->assertSessionHas('error', fn (string $m) => str_contains($m, 'ambiente de pruebas'));

    expect($empresa->certificados()->count())->toBe(0);
});

test('la ficha ofrece generarlo solo en pruebas y avisa cuando el activo es de prueba', function () {
    $piloto = Empresa::factory()->create();
    Certificado::factory()->for($piloto)->create(['emitido_por' => Certificado::EMISOR_AUTOFIRMADO]);

    $this->get(route('admin.empresas.show', $piloto))
        ->assertSee('Generar certificado de prueba')
        ->assertSee('Autofirmado de prueba');

    $this->get(route('admin.empresas.show', Empresa::factory()->enProduccion()->create()))
        ->assertDontSee('Generar certificado de prueba');
});

test('la emision de produccion se niega a firmar con un autofirmado', function () {
    Queue::fake();
    $empresa = Empresa::factory()->enProduccion()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $pv = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);
    Cufd::factory()->create(['punto_venta_id' => $pv->id]);
    Certificado::factory()->for($empresa)->firmable()->create(['emitido_por' => Certificado::EMISOR_AUTOFIRMADO]);

    $venta = [
        'sucursal' => 0,
        'punto_venta' => 0,
        'comprador' => ['tipo_documento' => 1, 'numero_documento' => '1234567', 'razon_social' => 'JUAN PEREZ'],
        'metodo_pago' => 1,
        'items' => [['codigo_producto_sin' => 83141, 'descripcion' => 'X', 'cantidad' => 1, 'unidad_medida' => 58, 'precio_unitario' => 10]],
    ];

    try {
        app(EmisorFactura::class)->emitir($empresa, $venta);
        $this->fail('Debia rechazar la firma con un autofirmado en produccion.');
    } catch (FacturaInvalidaException $e) {
        expect(implode(' ', $e->errores))->toContain('autofirmado de prueba');
    }

    // Rechazo dentro de la transaccion: ni factura ni numero consumido.
    expect(Factura::count())->toBe(0)
        ->and($pv->fresh()->siguiente_factura)->toBe(1);
});
