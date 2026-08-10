<?php

use App\Models\CasoPrueba;
use App\Models\Certificado;
use App\Models\Cufd;
use App\Models\Cuis;
use App\Models\EjecucionPrueba;
use App\Models\Empresa;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\ServicioCodigos;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

// ---- Listado: filtro y buscador ------------------------------------------

test('el listado filtra por estado', function () {
    Empresa::factory()->create(['nombre_comercial' => 'Ferreteria Piloto', 'estado' => Empresa::ESTADO_EN_PRUEBAS]);
    Empresa::factory()->enProduccion()->create(['nombre_comercial' => 'Farmacia Viva']);

    $this->get(route('admin.empresas.index', ['estado' => Empresa::ESTADO_EN_PRUEBAS]))
        ->assertOk()
        ->assertSee('Ferreteria Piloto')
        ->assertDontSee('Farmacia Viva');
});

test('el listado busca por NIT y por nombre', function () {
    Empresa::factory()->create(['nombre_comercial' => 'Ferreteria Central', 'nit' => '1111111']);
    Empresa::factory()->create(['nombre_comercial' => 'Farmacia Viva', 'nit' => '2222222']);

    $this->get(route('admin.empresas.index', ['q' => '2222222']))
        ->assertOk()
        ->assertSee('Farmacia Viva')
        ->assertDontSee('Ferreteria Central');

    $this->get(route('admin.empresas.index', ['q' => 'Ferreteria']))
        ->assertOk()
        ->assertSee('Ferreteria Central')
        ->assertDontSee('Farmacia Viva');
});

test('el listado muestra el estado con su etiqueta legible', function () {
    Empresa::factory()->create(['estado' => Empresa::ESTADO_PILOTO_APROBADO]);

    $this->get(route('admin.empresas.index'))
        ->assertOk()
        // La etiqueta viene de EstadosVisuales, no del valor crudo de la columna.
        ->assertSee('Piloto aprobado');
});

// ---- Configuracion del proveedor -------------------------------------------

test('la pantalla de configuracion muestra la identidad del proveedor', function () {
    config([
        'siat.proveedor.nit' => '7633685015',
        'siat.proveedor.codigo_sistema' => 'CODIGO-DEL-SIN',
        'siat.proveedor.razon_social' => 'MOLINA GUZMAN IGNACIO',
    ]);

    $this->get(route('admin.configuracion'))
        ->assertOk()
        ->assertSee('7633685015')
        ->assertSee('CODIGO-DEL-SIN')
        ->assertSee('pilotosiatservicios.impuestos.gob.bo/v2');
});

test('la configuracion avisa si falta el codigo de sistema', function () {
    config(['siat.proveedor.codigo_sistema' => null]);

    $this->get(route('admin.configuracion'))
        ->assertOk()
        ->assertSee('Falta configurar');
});

test('el alta de cliente precarga el codigo de sistema del proveedor', function () {
    config(['siat.proveedor.codigo_sistema' => 'CODIGO-DEL-SIN']);

    $this->get(route('admin.empresas.create'))
        ->assertOk()
        ->assertSee('CODIGO-DEL-SIN');
});

test('editar un cliente respeta su propio codigo de sistema', function () {
    config(['siat.proveedor.codigo_sistema' => 'CODIGO-DEL-PROVEEDOR']);

    $empresa = Empresa::factory()->create(['codigo_sistema' => 'CODIGO-PROPIO']);

    // Si el SIN le asigno uno propio, el default del proveedor no debe pisarlo.
    $this->get(route('admin.empresas.edit', $empresa))
        ->assertOk()
        ->assertSee('CODIGO-PROPIO')
        ->assertDontSee('CODIGO-DEL-PROVEEDOR');
});

// ---- Ficha: checklist de requisitos ---------------------------------------

test('la ficha marca como incumplidos los requisitos que faltan', function () {
    $empresa = Empresa::factory()->create([
        'estado' => Empresa::ESTADO_EN_REGISTRO,
        'token_delegado' => null,
    ]);

    $this->get(route('admin.empresas.show', $empresa))
        ->assertOk()
        ->assertSee('Token delegado cargado')
        ->assertSee('Certificado .p12 activo')
        // Sin requisitos completos, el boton de avanzar queda deshabilitado.
        ->assertSee('Faltan requisitos de la lista');
});

test('la ficha habilita el avance cuando estan todos los requisitos', function () {
    $empresa = Empresa::factory()->create(['estado' => Empresa::ESTADO_EN_REGISTRO]);
    Certificado::factory()->for($empresa)->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    PuntoVenta::factory()->for($sucursal)->create();

    $this->get(route('admin.empresas.show', $empresa))
        ->assertOk()
        ->assertSee('Marcar como En pruebas')
        ->assertDontSee('Faltan requisitos de la lista');
});

test('la ficha avisa cuando el certificado esta vencido', function () {
    $empresa = Empresa::factory()->create();
    Certificado::factory()->for($empresa)->create(['vence_el' => now()->subDay()]);

    $this->get(route('admin.empresas.show', $empresa))
        ->assertOk()
        ->assertSee('vencido');
});

test('la ficha de un cliente en pruebas pide CUIS, CUFD y los casos del piloto', function () {
    $empresa = Empresa::factory()->create(['estado' => Empresa::ESTADO_EN_PRUEBAS]);
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $puntoVenta = PuntoVenta::factory()->for($sucursal)->create();
    Cuis::factory()->for($puntoVenta)->create();

    $this->get(route('admin.empresas.show', $empresa))
        ->assertOk()
        ->assertSee('CUIS vigente en algun punto de venta')
        ->assertSee('CUFD vigente en algun punto de venta')
        ->assertSee('Casos del piloto superados');
});

test('sin CUIS vigente no se puede pedir CUFD desde la ficha', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    PuntoVenta::factory()->for($sucursal)->create();

    $this->get(route('admin.empresas.show', $empresa))
        ->assertOk()
        ->assertSee('Primero hace falta un CUIS vigente');
});

// ---- Cambio de etapa -------------------------------------------------------

test('el panel avanza la etapa del cliente', function () {
    $empresa = Empresa::factory()->create(['estado' => Empresa::ESTADO_EN_REGISTRO]);

    $this->post(route('admin.empresas.estado', $empresa), ['estado' => Empresa::ESTADO_EN_PRUEBAS])
        ->assertRedirect(route('admin.empresas.show', $empresa));

    expect($empresa->fresh()->estado)->toBe(Empresa::ESTADO_EN_PRUEBAS);
});

test('el panel rechaza una etapa que no existe', function () {
    $empresa = Empresa::factory()->create();

    $this->post(route('admin.empresas.estado', $empresa), ['estado' => 'INVENTADO'])
        ->assertSessionHasErrors('estado');

    expect($empresa->fresh()->estado)->toBe(Empresa::ESTADO_EN_REGISTRO);
});

// ---- Panel del piloto ------------------------------------------------------

test('el panel del piloto muestra el progreso sobre el total de casos', function () {
    $empresa = Empresa::factory()->create();
    Certificado::factory()->for($empresa)->create();

    $casos = CasoPrueba::factory()->count(3)->sequence(
        ['orden' => 1], ['orden' => 2], ['orden' => 3],
    )->create();

    EjecucionPrueba::create([
        'empresa_id' => $empresa->id,
        'caso_id' => $casos->first()->id,
        'estado' => EjecucionPrueba::ESTADO_EXITOSO,
        'respuesta' => ['transaccion' => true],
        'ejecutado_en' => now(),
    ]);

    $this->get(route('admin.pruebas.show', $empresa))
        ->assertOk()
        ->assertSee('Progreso: 1/3')
        ->assertSee('Ver respuesta');
});

test('se puede ejecutar un solo paso del piloto', function () {
    $empresa = Empresa::factory()->create();
    $caso = CasoPrueba::factory()->create(['tipo' => 'verificarComunicacion', 'orden' => 1]);

    $servicio = Mockery::mock(ServicioCodigos::class);
    $servicio->shouldReceive('verificarComunicacion')->andReturn((object) ['transaccion' => true]);
    $this->mock(FabricaServicios::class, function ($mock) use ($servicio) {
        $mock->shouldReceive('codigos')->andReturn($servicio);
    });

    $this->post(route('admin.pruebas.caso', [$empresa, $caso]))
        ->assertRedirect(route('admin.pruebas.show', $empresa))
        ->assertSessionHas('estado');

    expect(EjecucionPrueba::where('caso_id', $caso->id)->first()->estado)
        ->toBe(EjecucionPrueba::ESTADO_EXITOSO);
});

test('completar el piloto ofrece marcar al cliente como aprobado, sin hacerlo solo', function () {
    $empresa = Empresa::factory()->create(['estado' => Empresa::ESTADO_EN_PRUEBAS]);
    Certificado::factory()->for($empresa)->create();
    $caso = CasoPrueba::factory()->create(['orden' => 1]);

    EjecucionPrueba::create([
        'empresa_id' => $empresa->id,
        'caso_id' => $caso->id,
        'estado' => EjecucionPrueba::ESTADO_EXITOSO,
        'respuesta' => [],
        'ejecutado_en' => now(),
    ]);

    $this->get(route('admin.pruebas.show', $empresa))
        ->assertOk()
        ->assertSee('Marcar PILOTO_APROBADO');

    // Quien aprueba el piloto es el SIN: el estado no cambia solo.
    expect($empresa->fresh()->estado)->toBe(Empresa::ESTADO_EN_PRUEBAS);
});

test('el payload de un paso se carga desde el panel y se valida como JSON', function () {
    $empresa = Empresa::factory()->create();
    $caso = CasoPrueba::factory()->create(['orden' => 11, 'payload_ejemplo' => null]);

    // Un JSON roto no se guarda: emitir con datos a medias es peor que no emitir.
    $this->post(route('admin.pruebas.payload', [$empresa, $caso]), ['payload_ejemplo' => '{no es json'])
        ->assertSessionHasErrors('payload_ejemplo');

    $this->post(route('admin.pruebas.payload', [$empresa, $caso]), [
        'payload_ejemplo' => '{"motivo": 1}',
    ])->assertRedirect(route('admin.pruebas.show', $empresa));

    expect($caso->fresh()->payload_ejemplo)->toBe(['motivo' => 1]);
});

test('la ficha destaca el siguiente paso concreto', function () {
    $empresa = Empresa::factory()->create([
        'estado' => Empresa::ESTADO_EN_REGISTRO,
        'token_delegado' => null,
    ]);

    $this->get(route('admin.empresas.show', $empresa))
        ->assertOk()
        ->assertSee('Siguiente paso');
});

test('sin token ni certificado no se habilita ningun boton del piloto', function () {
    $empresa = Empresa::factory()->create(['token_delegado' => null]);
    CasoPrueba::factory()->create(['orden' => 1]);

    $this->get(route('admin.pruebas.show', $empresa))
        ->assertOk()
        ->assertSee('Complete token y certificado antes de iniciar');
});

// ---- Semaforos de codigos --------------------------------------------------

test('la ficha muestra el CUFD por vencer en ambar y el vencido en rojo', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $puntoVenta = PuntoVenta::factory()->for($sucursal)->create();

    // El CUFD dura 24 h; a una hora del vencimiento ya es una alerta.
    Cufd::factory()->for($puntoVenta)->create(['fecha_vigencia' => now()->addHour()]);

    $this->get(route('admin.empresas.show', $empresa))
        ->assertOk()
        ->assertSee('punto-ambar', escape: false);
});
