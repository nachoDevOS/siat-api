<?php

use App\Models\Empresa;
use App\Models\Factura;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

// ---- El token delegado no vuelve al navegador -------------------------------

test('el formulario de edicion no imprime el token delegado', function () {
    $empresa = Empresa::factory()->create(['token_delegado' => 'TOKEN-SUPER-SECRETO']);

    $this->get(route('admin.empresas.edit', $empresa))
        ->assertOk()
        ->assertDontSee('TOKEN-SUPER-SECRETO');
});

test('guardar la edicion con el token vacio conserva el que ya estaba', function () {
    $empresa = Empresa::factory()->create(['token_delegado' => 'TOKEN-ORIGINAL']);

    // El campo va vacio porque nunca se rellena: vacio significa "no lo toques".
    $this->put(route('admin.empresas.update', $empresa), [
        'nombre_comercial' => $empresa->nombre_comercial,
        'razon_social' => $empresa->razon_social,
        'nit' => $empresa->nit,
        'codigo_ambiente' => 2,
        'codigo_modalidad' => 1,
        'estado' => Empresa::ESTADO_EN_REGISTRO,
        'token_delegado' => '',
    ])->assertRedirect();

    expect($empresa->fresh()->token_delegado)->toBe('TOKEN-ORIGINAL');
});

test('un token nuevo si reemplaza al anterior', function () {
    $empresa = Empresa::factory()->create(['token_delegado' => 'TOKEN-ORIGINAL']);

    $this->put(route('admin.empresas.update', $empresa), [
        'nombre_comercial' => $empresa->nombre_comercial,
        'razon_social' => $empresa->razon_social,
        'nit' => $empresa->nit,
        'codigo_ambiente' => 2,
        'codigo_modalidad' => 1,
        'estado' => Empresa::ESTADO_EN_REGISTRO,
        'token_delegado' => 'TOKEN-NUEVO',
    ])->assertRedirect();

    expect($empresa->fresh()->token_delegado)->toBe('TOKEN-NUEVO');
});

// ---- No se borra un cliente con documentos fiscales -------------------------

test('no se puede eliminar un cliente que ya emitio facturas', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $puntoVenta = PuntoVenta::factory()->for($sucursal)->create();

    Factura::factory()->create([
        'empresa_id' => $empresa->id,
        'punto_venta_id' => $puntoVenta->id,
    ]);

    // El borrado cascadea a facturas, que tienen obligacion de conservacion.
    $this->delete(route('admin.empresas.destroy', $empresa))->assertRedirect();

    expect(Empresa::find($empresa->id))->not->toBeNull()
        ->and(Factura::count())->toBe(1);
});

test('un cliente sin facturas si se puede eliminar', function () {
    $empresa = Empresa::factory()->create();

    $this->delete(route('admin.empresas.destroy', $empresa))
        ->assertRedirect(route('admin.empresas.index'));

    expect(Empresa::find($empresa->id))->toBeNull();
});
