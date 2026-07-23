<?php

use App\Models\Empresa;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// El panel exige sesion: todas las pruebas actuan como un usuario logueado.
beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

// Smoke tests: confirman que las vistas Blade del panel renderizan sin error.

test('el dashboard renderiza con sus indicadores', function () {
    Empresa::factory()->count(3)->create();

    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertSee('Empresas')
        ->assertSee('Salud SIAT');
});

test('la consola de administracion de la API renderiza', function () {
    $empresa = Empresa::factory()->create();

    $this->get(route('admin.api'))
        ->assertOk()
        ->assertSee('Endpoints disponibles')
        ->assertSee('v1/facturas')
        ->assertSee($empresa->nombre_comercial);
});

test('la raiz redirige al dashboard', function () {
    $this->get('/')->assertRedirect(route('admin.dashboard'));
});

test('el listado de empresas renderiza', function () {
    Empresa::factory()->count(2)->create();

    $this->get(route('admin.empresas.index'))->assertOk()->assertSee('Empresas');
});

test('la ficha de una empresa renderiza con sus relaciones', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    PuntoVenta::factory()->for($sucursal)->create();

    $this->get(route('admin.empresas.show', $empresa))
        ->assertOk()
        ->assertSee($empresa->nombre_comercial);
});

test('el panel de pruebas piloto renderiza', function () {
    $empresa = Empresa::factory()->create();

    $this->get(route('admin.pruebas.show', $empresa))->assertOk()->assertSee('Requisitos previos');
});

test('el monitor renderiza', function () {
    $this->get(route('admin.monitor'))->assertOk()->assertSee('Monitor');
});

test('crear una empresa genera su API key una sola vez', function () {
    $respuesta = $this->post(route('admin.empresas.store'), [
        'nombre_comercial' => 'Ferreteria Test',
        'razon_social' => 'FERRETERIA TEST SRL',
        'nit' => '1234567',
        'codigo_ambiente' => 2,
        'codigo_modalidad' => 1,
        'estado' => 'EN_REGISTRO',
    ]);

    $empresa = Empresa::firstWhere('nit', '1234567');

    $respuesta->assertRedirect(route('admin.empresas.show', $empresa));
    // La clave se muestra en el flash una unica vez.
    $respuesta->assertSessionHas('api_key');
    expect($empresa->api_key_hash)->not->toBeNull();
});
