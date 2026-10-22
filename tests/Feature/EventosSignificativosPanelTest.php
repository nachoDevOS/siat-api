<?php

use App\Models\Cufd;
use App\Models\Cuis;
use App\Models\Empresa;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\ServicioOperaciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * PV 0 de una empresa del piloto (ambiente 2, con rutas /v2) con CUIS y CUFD
 * vigentes.
 */
function puntoVentaConCodigosVigentes(): PuntoVenta
{
    $empresa = Empresa::factory()->create(['codigo_ambiente' => 2]);
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $puntoVenta = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);
    $cuis = Cuis::factory()->for($puntoVenta)->create(['codigo' => 'CUIS-PV0']);
    Cufd::factory()->for($puntoVenta)->create(['codigo' => 'CUFD-VIGENTE', 'cuis_id' => $cuis->id]);

    return $puntoVenta;
}

/**
 * Hace que la fabrica entregue estas operaciones para la version indicada.
 */
function operacionesDelSin(string $version): ServicioOperaciones
{
    $operaciones = Mockery::mock(ServicioOperaciones::class);

    test()->mock(FabricaServicios::class, function ($mock) use ($operaciones, $version) {
        $mock->shouldReceive('operaciones')->with(Mockery::any(), $version)->andReturn($operaciones);
    });

    return $operaciones;
}

function evento(int $motivo, int $codigo, string $inicio): object
{
    return (object) [
        'codigoEvento' => $motivo,
        'codigoRecepcionEventoSignificativo' => $codigo,
        'descripcion' => "MOTIVO {$motivo}",
        'fechaInicio' => $inicio,
        'fechaFin' => Carbon::parse($inicio)->addMinute()->format('Y-m-d\TH:i:s.v'),
    ];
}

beforeEach(fn () => $this->actingAs(User::factory()->create()));

test('lista los eventos del SIN del dia pedido, ordenados por inicio', function () {
    $puntoVenta = puntoVentaConCodigosVigentes();

    operacionesDelSin('v3')->shouldReceive('consultarEventos')->once()
        ->withArgs(fn (int $sucursal, int $pv, string $cuis, string $cufd, Carbon $fecha): bool => $sucursal === 0
            && $pv === 0
            && $cuis === 'CUIS-PV0'
            && $cufd === 'CUFD-VIGENTE'
            && $fecha->toDateString() === '2026-10-20')
        ->andReturn((object) ['RespuestaListaEventos' => (object) [
            'transaccion' => true,
            'listaCodigos' => [
                evento(7, 9965443, '2026-10-20T23:20:24.000'),
                evento(1, 9965382, '2026-10-20T22:49:54.000'),
            ],
        ]]);

    $this->get(route('admin.puntos-venta.eventos', [$puntoVenta, 'fecha' => '2026-10-20']))
        ->assertOk()
        ->assertSee('2 eventos del 20/10/2026')
        ->assertSee('Motivo 1: 1')
        ->assertSee('Motivo 7: 1')
        ->assertSeeInOrder(['9965382', '9965443']);
});

test('un solo evento llega como objeto suelto y igual se lista', function () {
    $puntoVenta = puntoVentaConCodigosVigentes();

    operacionesDelSin('v3')->shouldReceive('consultarEventos')->once()
        ->andReturn((object) ['RespuestaListaEventos' => (object) [
            'transaccion' => true,
            'listaCodigos' => evento(7, 9966033, '2026-10-20T23:45:49.000'),
        ]]);

    $this->get(route('admin.puntos-venta.eventos', [$puntoVenta, 'fecha' => '2026-10-20']))
        ->assertOk()
        ->assertSee('1 evento del 20/10/2026')
        ->assertSee('9966033');
});

test('el 957 del SIN es una lista vacia, no un error', function () {
    $puntoVenta = puntoVentaConCodigosVigentes();

    operacionesDelSin('v3')->shouldReceive('consultarEventos')->once()
        ->andReturn((object) ['RespuestaListaEventos' => (object) [
            'transaccion' => false,
            'mensajesList' => (object) ['codigo' => 957, 'descripcion' => 'NO EXISTE REGISTRO DE EVENTO SIGNIFICATIVO EN LA BASE DE DATOS DEL SIN'],
        ]]);

    $this->get(route('admin.puntos-venta.eventos', [$puntoVenta, 'fecha' => '2026-10-21']))
        ->assertOk()
        ->assertSee('0 eventos del 21/10/2026')
        ->assertSee('El SIN no tiene eventos registrados')
        ->assertDontSee('Error del SIAT');
});

test('otro rechazo del SIN se muestra como error', function () {
    $puntoVenta = puntoVentaConCodigosVigentes();

    operacionesDelSin('v3')->shouldReceive('consultarEventos')->once()
        ->andReturn((object) ['RespuestaListaEventos' => (object) [
            'transaccion' => false,
            'mensajesList' => (object) ['codigo' => 914, 'descripcion' => 'CODIGO UNICO DE FACTURACION DIARIA (CUFD) INVALIDO'],
        ]]);

    $this->get(route('admin.puntos-venta.eventos', [$puntoVenta, 'fecha' => '2026-10-20']))
        ->assertOk()
        ->assertSee('Error del SIAT: [914]');
});

test('en el piloto se puede consultar por la ruta /v2', function () {
    $puntoVenta = puntoVentaConCodigosVigentes();

    operacionesDelSin('v2')->shouldReceive('consultarEventos')->once()
        ->andReturn((object) ['RespuestaListaEventos' => (object) [
            'transaccion' => true,
            'listaCodigos' => [evento(5, 9965421, '2026-10-20T23:09:09.000')],
        ]]);

    $this->get(route('admin.puntos-venta.eventos', [$puntoVenta, 'fecha' => '2026-10-20', 'version' => 'v2']))
        ->assertOk()
        ->assertSee('9965421');
});

test('en produccion no hay ruta /v2 para elegir', function () {
    $puntoVenta = puntoVentaConCodigosVigentes();
    $puntoVenta->sucursal->empresa->update(['codigo_ambiente' => 1]);

    $this->get(route('admin.puntos-venta.eventos', $puntoVenta))
        ->assertOk()
        ->assertDontSee('name="version"', false);

    $this->get(route('admin.puntos-venta.eventos', [$puntoVenta, 'fecha' => '2026-10-20', 'version' => 'v2']))
        ->assertSessionHasErrors('version');
});

test('sin fecha solo muestra el formulario y no consulta al SIN', function () {
    $puntoVenta = puntoVentaConCodigosVigentes();

    operacionesDelSin('v3')->shouldNotReceive('consultarEventos');

    $this->get(route('admin.puntos-venta.eventos', $puntoVenta))
        ->assertOk()
        ->assertSee('Consultar en el SIN')
        ->assertDontSee('Error del SIAT');
});

test('sin CUFD vigente avisa en vez de mandar uno vencido al SIN', function () {
    $puntoVenta = puntoVentaConCodigosVigentes();
    $puntoVenta->cufds()->update(['fecha_vigencia' => now()->subHour()]);

    operacionesDelSin('v3')->shouldNotReceive('consultarEventos');

    $this->get(route('admin.puntos-venta.eventos', [$puntoVenta, 'fecha' => '2026-10-20']))
        ->assertOk()
        ->assertSee('necesita CUIS y CUFD vigentes');
});

test('la ficha y la pantalla de codigos enlazan a los eventos', function () {
    $puntoVenta = puntoVentaConCodigosVigentes();
    $url = route('admin.puntos-venta.eventos', $puntoVenta);

    $this->get(route('admin.puntos-venta.codigos', $puntoVenta))->assertOk()->assertSee($url, false);
    $this->get(route('admin.empresas.show', $puntoVenta->sucursal->empresa))->assertOk()->assertSee($url, false);
});

test('la consulta de eventos exige sesion', function () {
    auth()->logout();

    $this->get(route('admin.puntos-venta.eventos', puntoVentaConCodigosVigentes()))
        ->assertRedirect(route('login'));
});
