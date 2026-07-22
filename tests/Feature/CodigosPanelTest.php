<?php

use App\Models\PuntoVenta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('la carga manual de CUFD deja el punto de venta listo para emitir', function () {
    $pv = PuntoVenta::factory()->create();

    $this->post(route('admin.codigos.cufd.manual', $pv), [
        'codigo' => 'CUFD-TEST',
        'codigo_control' => 'A1B2C3',
    ])->assertRedirect();

    // Tras la carga manual hay un CUFD vigente con su codigo_control.
    $vigente = $pv->cufdVigente();
    expect($vigente)->not->toBeNull()
        ->and($vigente->codigo_control)->toBe('A1B2C3');
});

test('la carga manual de CUIS y CAFC funciona', function () {
    $pv = PuntoVenta::factory()->create();

    $this->post(route('admin.codigos.cuis.manual', $pv), ['codigo' => 'CUIS-1'])->assertRedirect();
    $this->post(route('admin.codigos.cafc.manual', $pv), ['codigo' => 'CAFC-1', 'cantidad_facturas' => 500])->assertRedirect();

    expect($pv->cuisVigente())->not->toBeNull()
        ->and($pv->cafcs()->where('fecha_vigencia', '>', now())->exists())->toBeTrue();
});

test('solicitar CUFD al SIAT sin CUIS no rompe el panel', function () {
    $pv = PuntoVenta::factory()->create();

    // Sin CUIS vigente y sin WSDL alcanzable: debe redirigir con aviso, no 500.
    $this->post(route('admin.codigos.cufd', $pv))
        ->assertRedirect()
        ->assertSessionHas('estado');
});
