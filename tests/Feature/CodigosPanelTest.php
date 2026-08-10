<?php

use App\Exceptions\SiatException;
use App\Models\Cuis;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\ServicioCodigos;
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

/**
 * Sustituye la fabrica por un doble. El controlador construia el SiatClient a
 * mano y este camino quedaba sin cobertura (deuda #7 del analisis).
 */
function fabricaDeCodigos(ServicioCodigos $servicio): void
{
    test()->mock(FabricaServicios::class, function ($mock) use ($servicio) {
        $mock->shouldReceive('codigos')->andReturn($servicio);
    });
}

test('solicitar CUIS al SIAT guarda el codigo devuelto', function () {
    $pv = PuntoVenta::factory()->create();

    $servicio = Mockery::mock(ServicioCodigos::class);
    $servicio->shouldReceive('solicitarCuis')->andReturn((object) [
        'RespuestaCuis' => (object) ['codigo' => 'CUIS-DEL-SIN'],
    ]);
    fabricaDeCodigos($servicio);

    $this->post(route('admin.codigos.cuis', $pv))->assertRedirect();

    expect($pv->cuisVigente()->codigo)->toBe('CUIS-DEL-SIN');
});

test('solicitar CUFD al SIAT guarda codigo y codigo_control', function () {
    $pv = PuntoVenta::factory()->create();
    Cuis::factory()->for($pv)->create();

    $servicio = Mockery::mock(ServicioCodigos::class);
    $servicio->shouldReceive('solicitarCufd')->andReturn((object) [
        'RespuestaCufd' => (object) [
            'codigo' => 'CUFD-DEL-SIN',
            'codigoControl' => 'CTRL-99',
            'direccion' => 'Av. Siempre Viva 742',
        ],
    ]);
    fabricaDeCodigos($servicio);

    $this->post(route('admin.codigos.cufd', $pv))->assertRedirect();

    // El codigo_control es la pieza que despues entra al calculo del CUF.
    $vigente = $pv->cufdVigente();
    expect($vigente->codigo)->toBe('CUFD-DEL-SIN')
        ->and($vigente->codigo_control)->toBe('CTRL-99');
});

test('un error del SIAT al solicitar codigos se muestra como aviso', function () {
    $pv = PuntoVenta::factory()->create();

    $servicio = Mockery::mock(ServicioCodigos::class);
    $servicio->shouldReceive('solicitarCuis')->andThrow(new SiatException('WSDL inalcanzable'));
    fabricaDeCodigos($servicio);

    $this->post(route('admin.codigos.cuis', $pv))
        ->assertRedirect()
        ->assertSessionHas('estado', 'Error del SIAT: WSDL inalcanzable');

    expect($pv->cuisVigente())->toBeNull();
});
