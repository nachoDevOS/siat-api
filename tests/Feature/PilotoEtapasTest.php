<?php

use App\Jobs\EjecutarCasoPrueba;
use App\Jobs\EnviarFacturaAlSiat;
use App\Models\CasoPrueba;
use App\Models\Catalogo;
use App\Models\Certificado;
use App\Models\Cufd;
use App\Models\Cuis;
use App\Models\EjecucionPrueba;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\FacturaAnulada;
use App\Models\LeyendaFactura;
use App\Models\ProductoServicio;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Contingencia\ArmadorPaquete;
use App\Services\Factura\FirmadorXml;
use App\Services\Pruebas\GeneradorPaquetePrueba;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\ServicioCodigos;
use App\Services\Siat\ServicioFacturacion;
use App\Services\Siat\ServicioOperaciones;
use App\Services\Siat\ServicioSincronizacion;
use App\Services\Siat\SiatClient;
use Database\Seeders\CasosPruebaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->seed(CasosPruebaSeeder::class);
});

/**
 * Respuesta del SIN a una solicitud de CUIS, con la forma que entrega ext-soap.
 */
function respuestaCuis(string $codigo, string $vigencia = '2027-10-07T10:00:00.000-04:00'): object
{
    return (object) ['RespuestaCuis' => (object) [
        'codigo' => $codigo,
        'fechaVigencia' => $vigencia,
        'transaccion' => true,
    ]];
}

/**
 * Sustituye la capa SOAP: ninguna prueba debe salir al SIAT real.
 */
function codigosSimulados(ServicioCodigos $codigos, ?ServicioOperaciones $operaciones = null): void
{
    test()->mock(FabricaServicios::class, function ($mock) use ($codigos, $operaciones) {
        $mock->shouldReceive('codigos')->andReturn($codigos);
        $mock->shouldReceive('operaciones')->andReturn($operaciones ?? Mockery::mock(ServicioOperaciones::class));
    });
}

/**
 * Lo que el SIN contesta cuando ya habia un CUIS vigente: devuelve el viejo,
 * transaccion = false y el codigo 980. Copiado de una respuesta real del piloto.
 */
function respuestaCuisYaVigente(string $codigo): object
{
    return (object) ['RespuestaCuis' => (object) [
        'codigo' => $codigo,
        'fechaVigencia' => '2027-08-10T00:57:02.397-04:00',
        'mensajesList' => (object) ['codigo' => 980, 'descripcion' => 'EXISTE UN CUIS VIGENTE PARA LA SUCURSAL O PUNTO DE VENTA'],
        'transaccion' => false,
    ]];
}

/**
 * Empresa sin certificado, con la casa matriz y su punto de venta 0 en local
 * (el que crea el alta de sucursal). El punto de venta 1 NO esta en local.
 */
function empresaDeEtapaUno(): Empresa
{
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);

    return $empresa;
}

test('el seeder carga la etapa I con los parametros del portal', function () {
    $casos = CasoPrueba::where('etapa', 1)->orderBy('orden')->get();

    expect($casos)->toHaveCount(2)
        ->and($casos->pluck('payload_ejemplo')->all())->toBe([
            ['codigoSucursal' => 0, 'codigoPuntoVenta' => 1],
            ['codigoSucursal' => 0, 'codigoPuntoVenta' => 0],
        ])
        ->and($casos->pluck('pruebas_esperadas')->all())->toBe([1, 1]);

    // Los 17 pasos viejos siguen y no se pisaron con los de la etapa.
    expect(CasoPrueba::whereNull('etapa')->count())->toBe(17);
});

test('la etapa I pide un CUIS por cada punto de venta del portal, sin certificado', function () {
    $empresa = empresaDeEtapaUno();

    $codigos = Mockery::mock(ServicioCodigos::class);
    // Siempre con los codigos del portal, este o no el punto de venta en local.
    $codigos->shouldReceive('solicitarCuisPara')->once()->with(0, 1)->andReturn(respuestaCuis('CUIS-PV1'));
    $codigos->shouldReceive('solicitarCuisPara')->once()->with(0, 0)->andReturn(respuestaCuis('CUIS-PV0'));
    codigosSimulados($codigos);

    $this->post(route('admin.pruebas.etapa', [$empresa, 1]))
        ->assertRedirect(route('admin.pruebas.show', $empresa))
        ->assertSessionHas('estado', fn (string $m) => str_contains($m, '2 prueba(s) enviadas, todas OK'));

    expect(EjecucionPrueba::where('estado', EjecucionPrueba::ESTADO_EXITOSO)->count())->toBe(2);

    // Solo el PV 0 esta en local: su CUIS quedo guardado con la vigencia del SIN.
    $cuis = Cuis::sole();
    expect($cuis->codigo)->toBe('CUIS-PV0')
        ->and($cuis->fecha_vigencia->year)->toBe(2027);
});

test('reejecutar una etapa completa no vuelve a llamar al SIN', function () {
    $empresa = empresaDeEtapaUno();

    $codigos = Mockery::mock(ServicioCodigos::class);
    $codigos->shouldReceive('solicitarCuisPara')->twice()->andReturn(respuestaCuis('CUIS-PV1'), respuestaCuis('CUIS-PV0'));
    codigosSimulados($codigos);

    $this->post(route('admin.pruebas.etapa', [$empresa, 1]));
    $this->post(route('admin.pruebas.etapa', [$empresa, 1]))
        ->assertSessionHas('estado', fn (string $m) => str_contains($m, 'ya estaba completa'));

    expect(EjecucionPrueba::count())->toBe(2);
});

test('un rechazo del SIN queda FALLIDO con su motivo y no suma como correcta', function () {
    $empresa = empresaDeEtapaUno();
    $caso = CasoPrueba::where('etapa', 1)->where('orden', 1)->sole();

    // El SIN rechaza con HTTP 200 y el codigo vacio.
    $rechazo = (object) ['RespuestaCuis' => (object) [
        'codigo' => '',
        'transaccion' => false,
        'mensajesList' => (object) ['codigo' => 994, 'descripcion' => 'PUNTO DE VENTA INEXISTENTE'],
    ]];

    $codigos = Mockery::mock(ServicioCodigos::class);
    $codigos->shouldReceive('solicitarCuisPara')->once()->andReturn($rechazo);
    codigosSimulados($codigos);

    $this->post(route('admin.pruebas.caso', [$empresa, $caso]))->assertRedirect();

    $ejecucion = EjecucionPrueba::sole();
    expect($ejecucion->estado)->toBe(EjecucionPrueba::ESTADO_FALLIDO)
        ->and($ejecucion->respuesta['error'])->toContain('PUNTO DE VENTA INEXISTENTE');

    $this->get(route('admin.pruebas.show', $empresa))
        ->assertOk()
        ->assertSee('Etapa I')
        ->assertSee('0/2');
});

test('sin token no corre ninguna prueba', function () {
    $empresa = empresaDeEtapaUno();
    $empresa->update(['token_delegado' => null]);

    $codigos = Mockery::mock(ServicioCodigos::class);
    $codigos->shouldNotReceive('solicitarCuis', 'solicitarCuisPara');
    codigosSimulados($codigos);

    $this->post(route('admin.pruebas.etapa', [$empresa, 1]))
        ->assertSessionHas('estado', fn (string $m) => str_contains($m, 'token'));

    expect(EjecucionPrueba::count())->toBe(0);
});

test('una prueba que firma sigue exigiendo el certificado', function () {
    $empresa = empresaDeEtapaUno();
    $caso = CasoPrueba::where('tipo', 'recepcionFactura')->sole();

    $this->post(route('admin.pruebas.caso', [$empresa, $caso]))
        ->assertSessionHas('estado', fn (string $m) => str_contains($m, 'certificado'));

    expect(EjecucionPrueba::count())->toBe(0);
});

test('la vista muestra la etapa con sus parametros y habilita correrla sin certificado', function () {
    $empresa = empresaDeEtapaUno();

    $this->get(route('admin.pruebas.show', $empresa))
        ->assertOk()
        ->assertSee('Etapa I — Obtencion de CUIS', false)
        ->assertSee('codigoPuntoVenta')
        ->assertSee('Ejecutar etapa')
        ->assertDontSee('Complete token y certificado antes de iniciar.')
        // La secuencia vieja de 17 pasos ya no se muestra.
        ->assertDontSee('Secuencia anterior');
});

test('una etapa que no existe da 404', function () {
    $empresa = empresaDeEtapaUno();

    $this->post(route('admin.pruebas.etapa', [$empresa, 9]))->assertNotFound();
});

test('si el SIN devuelve el CUIS ya vigente (980) la prueba NO cuenta, pero el codigo se guarda', function () {
    $empresa = empresaDeEtapaUno();
    $caso = CasoPrueba::where('etapa', 1)->where('orden', 2)->sole();

    $codigos = Mockery::mock(ServicioCodigos::class);
    $codigos->shouldReceive('solicitarCuisPara')->once()->with(0, 0)->andReturn(respuestaCuisYaVigente('F533CEF5'));
    codigosSimulados($codigos);

    $this->post(route('admin.pruebas.caso', [$empresa, $caso]))->assertRedirect();

    $ejecucion = EjecucionPrueba::sole();
    expect($ejecucion->estado)->toBe(EjecucionPrueba::ESTADO_FALLIDO)
        ->and($ejecucion->respuesta['error'])->toContain('NO lo cuenta');

    // El codigo es valido: se usa en las etapas siguientes aunque no sume.
    expect(Cuis::sole()->codigo)->toBe('F533CEF5');

    $this->get(route('admin.pruebas.show', $empresa))
        ->assertSee('Cerrar operaciones en el PV 0')
        ->assertSee('0/2');
});

test('cerrar operaciones manda al SIN el CUIS vigente del punto de venta de la prueba', function () {
    $empresa = empresaDeEtapaUno();
    $caso = CasoPrueba::where('etapa', 1)->where('orden', 2)->sole();

    $codigos = Mockery::mock(ServicioCodigos::class);
    $codigos->shouldReceive('solicitarCuisPara')->once()->with(0, 0)->andReturn(respuestaCuisYaVigente('F533CEF5'));

    $operaciones = Mockery::mock(ServicioOperaciones::class);
    $operaciones->shouldReceive('cerrarOperacionesSistema')->once()->with(0, 0, 'F533CEF5')
        ->andReturn((object) ['RespuestaCierreSistemas' => (object) ['codigoSistema' => 'X', 'transaccion' => true]]);
    codigosSimulados($codigos, $operaciones);

    $this->post(route('admin.pruebas.cerrar-operaciones', [$empresa, $caso]))
        ->assertSessionHas('estado', fn (string $m) => str_contains($m, 'Operaciones cerradas'));

    // No es una prueba del portal: no deja ejecucion.
    expect(EjecucionPrueba::count())->toBe(0);
});

test('si el SIN no cierra las operaciones se muestra su motivo', function () {
    $empresa = empresaDeEtapaUno();
    $caso = CasoPrueba::where('etapa', 1)->where('orden', 2)->sole();

    $codigos = Mockery::mock(ServicioCodigos::class);
    $codigos->shouldReceive('solicitarCuisPara')->andReturn(respuestaCuisYaVigente('F533CEF5'));

    $operaciones = Mockery::mock(ServicioOperaciones::class);
    $operaciones->shouldReceive('cerrarOperacionesSistema')->andReturn((object) ['RespuestaCierreSistemas' => (object) [
        'transaccion' => false,
        'mensajesList' => (object) ['codigo' => 1, 'descripcion' => 'MOTIVO DEL SIN'],
    ]]);
    codigosSimulados($codigos, $operaciones);

    $this->post(route('admin.pruebas.cerrar-operaciones', [$empresa, $caso]))
        ->assertSessionHas('estado', fn (string $m) => str_contains($m, 'MOTIVO DEL SIN'));
});

// --- Etapa II: sincronizacion de catalogos ----------------------------------

/**
 * Sustituye la sincronizacion del SIN.
 */
function sincronizacionSimulada(ServicioSincronizacion $sincronizacion): void
{
    test()->mock(FabricaServicios::class, function ($mock) use ($sincronizacion) {
        $mock->shouldReceive('sincronizacion')->andReturn($sincronizacion);
    });
}

/**
 * Empresa con el PV 1 configurado y su CUIS vigente, como la deja la etapa I.
 */
function empresaConCuisEnPv1(): Empresa
{
    $empresa = empresaDeEtapaUno();
    $sucursal = $empresa->sucursales()->sole();
    $pv1 = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 1]);
    Cuis::factory()->create(['punto_venta_id' => $pv1->id, 'codigo' => 'CUIS-PV1', 'fecha_vigencia' => now()->addYear()]);

    return $empresa;
}

function catalogoAceptado(int $filas = 3): object
{
    return (object) ['RespuestaListaActividades' => (object) [
        'transaccion' => true,
        'listaActividades' => array_fill(0, $filas, (object) ['codigoCaeb' => '1', 'descripcion' => 'X']),
    ]];
}

test('el seeder carga la etapa II: 18 catalogos x 2 puntos de venta, 50 cada uno', function () {
    $casos = CasoPrueba::where('etapa', 2)->orderBy('orden')->get();

    expect($casos)->toHaveCount(36)
        ->and($casos->pluck('pruebas_esperadas')->unique()->all())->toBe([50])
        ->and($casos->pluck('payload_ejemplo.operacion')->unique())->toHaveCount(18)
        // Igual que el portal: impares PV 1, pares PV 0.
        ->and($casos[0]->payload_ejemplo['codigoPuntoVenta'])->toBe(1)
        ->and($casos[1]->payload_ejemplo['codigoPuntoVenta'])->toBe(0)
        ->and($casos[0]->payload_ejemplo['operacion'])->toBe('sincronizarActividades')
        ->and($casos[35]->payload_ejemplo['operacion'])->toBe('sincronizarParametricaUnidadMedida');

    // La etapa I no se toco.
    expect(CasoPrueba::where('etapa', 1)->count())->toBe(2);
});

test('una prueba de catalogo usa el CUIS del punto de venta de la prueba y cuenta los registros', function () {
    $empresa = empresaConCuisEnPv1();
    $caso = CasoPrueba::where('etapa', 2)->where('orden', 1)->sole();

    $sincronizacion = Mockery::mock(ServicioSincronizacion::class);
    $sincronizacion->shouldReceive('parametrica')->once()
        ->with('sincronizarActividades', 'CUIS-PV1', 0, 1)
        ->andReturn(catalogoAceptado(3));
    sincronizacionSimulada($sincronizacion);

    $this->post(route('admin.pruebas.caso', [$empresa, $caso]));

    $ejecucion = EjecucionPrueba::sole();
    expect($ejecucion->estado)->toBe(EjecucionPrueba::ESTADO_EXITOSO)
        ->and($ejecucion->respuesta['registros'])->toBe(3);
});

test('sin CUIS guardado para ese punto de venta la prueba de catalogo falla sin llamar al SIN', function () {
    $empresa = empresaDeEtapaUno(); // el PV 1 no existe en local
    $caso = CasoPrueba::where('etapa', 2)->where('orden', 1)->sole();

    $sincronizacion = Mockery::mock(ServicioSincronizacion::class);
    $sincronizacion->shouldNotReceive('parametrica');
    sincronizacionSimulada($sincronizacion);

    $this->post(route('admin.pruebas.caso', [$empresa, $caso]));

    expect(EjecucionPrueba::sole()->respuesta['error'])->toContain('no tiene CUIS vigente');
});

test('un catalogo rechazado por el SIN queda FALLIDO con su motivo', function () {
    $empresa = empresaConCuisEnPv1();
    $caso = CasoPrueba::where('etapa', 2)->where('orden', 1)->sole();

    $sincronizacion = Mockery::mock(ServicioSincronizacion::class);
    $sincronizacion->shouldReceive('parametrica')->andReturn((object) ['RespuestaListaActividades' => (object) [
        'transaccion' => false,
        'mensajesList' => (object) ['codigo' => 994, 'descripcion' => 'CUIS INVALIDO'],
    ]]);
    sincronizacionSimulada($sincronizacion);

    $this->post(route('admin.pruebas.caso', [$empresa, $caso]));

    expect(EjecucionPrueba::sole())
        ->estado->toBe(EjecucionPrueba::ESTADO_FALLIDO)
        ->respuesta->error->toContain('CUIS INVALIDO');
});

test('la etapa II va a la cola: un job por cada llamada que falta', function () {
    Queue::fake();
    $empresa = empresaConCuisEnPv1();

    $this->post(route('admin.pruebas.etapa', [$empresa, 2]))
        ->assertSessionHas('estado', fn (string $m) => str_contains($m, '1800 prueba(s) en cola'));

    Queue::assertPushed(EjecutarCasoPrueba::class, 1800);
    expect(EjecucionPrueba::count())->toBe(0);
});

test('completar una prueba encola solo lo que le falta', function () {
    Queue::fake();
    $empresa = empresaConCuisEnPv1();
    $caso = CasoPrueba::where('etapa', 2)->where('orden', 1)->sole();
    EjecucionPrueba::create(['empresa_id' => $empresa->id, 'caso_id' => $caso->id,
        'estado' => EjecucionPrueba::ESTADO_EXITOSO, 'ejecutado_en' => now()]);

    $this->post(route('admin.pruebas.completar', [$empresa, $caso]));

    Queue::assertPushed(EjecutarCasoPrueba::class, 49);
});

test('el job no se pasa de las esperadas ni sigue despues de un fallo del mismo lote', function () {
    $empresa = empresaConCuisEnPv1();
    $caso = CasoPrueba::where('etapa', 2)->where('orden', 1)->sole();

    $sincronizacion = Mockery::mock(ServicioSincronizacion::class);
    $sincronizacion->shouldNotReceive('parametrica');
    sincronizacionSimulada($sincronizacion);

    // Fallo dentro del lote: el resto del lote se descarta.
    $lote = now()->subMinute()->toDateTimeString();
    EjecucionPrueba::create(['empresa_id' => $empresa->id, 'caso_id' => $caso->id,
        'estado' => EjecucionPrueba::ESTADO_FALLIDO, 'ejecutado_en' => now()]);
    app()->call([new EjecutarCasoPrueba($empresa->id, $caso->id, $lote), 'handle']);

    // Prueba ya completa: tampoco llama.
    $caso->update(['pruebas_esperadas' => 1]);
    EjecucionPrueba::create(['empresa_id' => $empresa->id, 'caso_id' => $caso->id,
        'estado' => EjecucionPrueba::ESTADO_EXITOSO, 'ejecutado_en' => now()]);
    app()->call([new EjecutarCasoPrueba($empresa->id, $caso->id), 'handle']);

    expect(EjecucionPrueba::count())->toBe(2);
});

// --- Etapa III: obtencion de CUFD -------------------------------------------

function respuestaCufd(string $codigo, bool $transaccion = true): object
{
    return (object) ['RespuestaCufd' => (object) [
        'codigo' => $codigo,
        'codigoControl' => 'CTRL-'.$codigo,
        'direccion' => 'AV. SIEMPRE VIVA',
        'fechaVigencia' => now()->addDay()->format('Y-m-d\TH:i:s.vP'),
        'transaccion' => $transaccion,
    ]];
}

test('el seeder carga la etapa III: 100 CUFD para el PV 1 y 100 para el PV 0', function () {
    $casos = CasoPrueba::where('etapa', 3)->orderBy('orden')->get();

    expect($casos)->toHaveCount(2)
        ->and($casos->pluck('payload_ejemplo.codigoPuntoVenta')->all())->toBe([1, 0])
        ->and($casos->pluck('pruebas_esperadas')->all())->toBe([100, 100]);
});

test('una prueba de CUFD lo pide con el CUIS del punto de venta y lo guarda atado a el', function () {
    $empresa = empresaConCuisEnPv1();
    $caso = CasoPrueba::where('etapa', 3)->where('orden', 1)->sole();

    $codigos = Mockery::mock(ServicioCodigos::class);
    $codigos->shouldReceive('solicitarCufd')->once()
        ->with(Mockery::on(fn (PuntoVenta $pv) => $pv->codigo_punto_venta === 1), 'CUIS-PV1')
        ->andReturn(respuestaCufd('CUFD-1'));
    codigosSimulados($codigos);

    $this->post(route('admin.pruebas.caso', [$empresa, $caso]));

    expect(EjecucionPrueba::sole()->estado)->toBe(EjecucionPrueba::ESTADO_EXITOSO);

    $cufd = Cufd::sole();
    expect($cufd->codigo)->toBe('CUFD-1')
        ->and($cufd->codigo_control)->toBe('CTRL-CUFD-1')
        ->and($cufd->cuis->codigo)->toBe('CUIS-PV1');
});

test('un CUFD con transaccion false no cuenta como prueba', function () {
    $empresa = empresaConCuisEnPv1();
    $caso = CasoPrueba::where('etapa', 3)->where('orden', 1)->sole();

    $codigos = Mockery::mock(ServicioCodigos::class);
    $codigos->shouldReceive('solicitarCufd')->andReturn(respuestaCufd('CUFD-1', transaccion: false));
    codigosSimulados($codigos);

    $this->post(route('admin.pruebas.caso', [$empresa, $caso]));

    expect(EjecucionPrueba::sole()->estado)->toBe(EjecucionPrueba::ESTADO_FALLIDO);
});

test('sin CUIS guardado la prueba de CUFD falla sin llamar al SIN', function () {
    $empresa = empresaDeEtapaUno();
    $caso = CasoPrueba::where('etapa', 3)->where('orden', 1)->sole();

    $codigos = Mockery::mock(ServicioCodigos::class);
    $codigos->shouldNotReceive('solicitarCufd');
    codigosSimulados($codigos);

    $this->post(route('admin.pruebas.caso', [$empresa, $caso]));

    expect(EjecucionPrueba::sole()->respuesta['error'])->toContain('no tiene CUIS vigente');
});

test('la etapa III va a la cola: 200 llamadas', function () {
    Queue::fake();
    $empresa = empresaConCuisEnPv1();

    $this->post(route('admin.pruebas.etapa', [$empresa, 3]));

    Queue::assertPushed(EjecutarCasoPrueba::class, 200);
});

// --- Etapa IV: emision individual en linea -----------------------------------

/**
 * Empresa lista para facturar por el PV 1: certificado, CUIS, CUFD y los
 * catalogos del NIT ya guardados (producto homologado, leyenda, unidad).
 */
function empresaListaParaFacturar(): Empresa
{
    $empresa = empresaConCuisEnPv1();
    $pv1 = PuntoVenta::where('codigo_punto_venta', 1)->sole();
    Cufd::factory()->create(['punto_venta_id' => $pv1->id, 'cuis_id' => $pv1->cuisVigente()->id]);
    Certificado::factory()->for($empresa)->firmable()->create();
    ProductoServicio::create(['empresa_id' => $empresa->id, 'codigo_actividad' => '620100',
        'codigo_producto' => '83141', 'descripcion' => 'SERVICIO DE PRUEBA']);
    LeyendaFactura::create(['empresa_id' => $empresa->id, 'codigo_actividad' => '620100',
        'descripcion_leyenda' => 'Ley N 453: leyenda de prueba.']);
    Catalogo::factory()->create(['tipo' => 'unidades_medida', 'codigo_clasificador' => '58']);

    return $empresa;
}

function facturacionSimulada(ServicioFacturacion $facturacion): void
{
    test()->mock(FabricaServicios::class, function ($mock) use ($facturacion) {
        $mock->shouldReceive('facturacion')->andReturn($facturacion);
    });
}

function recepcion(string $codigoEstado, bool $transaccion = true, ?object $mensajes = null): object
{
    return (object) ['RespuestaServicioFacturacion' => (object) array_filter([
        'transaccion' => $transaccion,
        'codigoEstado' => $codigoEstado,
        'codigoRecepcion' => $transaccion ? 'REC-1' : null,
        'mensajesList' => $mensajes,
    ], fn ($v) => $v !== null)];
}

test('el seeder carga la etapa IV: 125 facturas por punto de venta', function () {
    $casos = CasoPrueba::where('etapa', 4)->orderBy('orden')->get();

    expect($casos)->toHaveCount(2)
        ->and($casos->pluck('payload_ejemplo.codigoPuntoVenta')->all())->toBe([1, 0])
        ->and($casos->pluck('pruebas_esperadas')->all())->toBe([125, 125])
        ->and($casos->every(fn (CasoPrueba $c) => $c->requiereCertificado()))->toBeTrue();
});

test('una factura de la etapa IV se emite, firma, envia en el momento y cuenta con 908', function () {
    Queue::fake();
    $empresa = empresaListaParaFacturar();
    $caso = CasoPrueba::where('etapa', 4)->where('orden', 1)->sole();

    $facturacion = Mockery::mock(ServicioFacturacion::class);
    $facturacion->shouldReceive('recepcionarFactura')->once()
        ->with(Mockery::type(Factura::class), Mockery::any(), 'CUIS-PV1')
        ->andReturn(recepcion('908'));
    facturacionSimulada($facturacion);

    $this->post(route('admin.pruebas.caso', [$empresa, $caso]));

    expect(EjecucionPrueba::sole()->estado)->toBe(EjecucionPrueba::ESTADO_EXITOSO);

    $factura = Factura::sole();
    expect($factura->estado)->toBe(Factura::ESTADO_VALIDADA)
        ->and($factura->codigo_recepcion)->toBe('REC-1')
        ->and($factura->xml_firmado)->toContain('<actividadEconomica>620100</actividadEconomica>')
        ->and($factura->xml_firmado)->toContain('Ley N 453');

    // Se envio en el momento: no queda ademas un envio en cola (seria doble).
    Queue::assertNotPushed(EnviarFacturaAlSiat::class);
});

test('una factura observada por el SIN no cuenta y queda OBSERVADA con el motivo', function () {
    $empresa = empresaListaParaFacturar();
    $caso = CasoPrueba::where('etapa', 4)->where('orden', 1)->sole();

    $facturacion = Mockery::mock(ServicioFacturacion::class);
    $facturacion->shouldReceive('recepcionarFactura')->andReturn(
        recepcion('902', false, (object) ['codigo' => 1037, 'descripcion' => 'FIRMA INVALIDA']),
    );
    facturacionSimulada($facturacion);

    $this->post(route('admin.pruebas.caso', [$empresa, $caso]));

    expect(EjecucionPrueba::sole())
        ->estado->toBe(EjecucionPrueba::ESTADO_FALLIDO)
        ->respuesta->error->toContain('FIRMA INVALIDA');
    expect(Factura::sole()->estado)->toBe(Factura::ESTADO_OBSERVADA);
});

test('sin certificado la etapa IV no corre', function () {
    $empresa = empresaConCuisEnPv1();

    $this->post(route('admin.pruebas.etapa', [$empresa, 4]))
        ->assertSessionHas('estado', fn (string $m) => str_contains($m, 'certificado'));

    expect(EjecucionPrueba::count())->toBe(0);
});

test('la vista agrupa cada etapa en un desplegable', function () {
    $empresa = empresaDeEtapaUno();

    $this->get(route('admin.pruebas.show', $empresa))
        ->assertOk()
        ->assertSee('<details class="tarjeta etapa" data-etapa="1"', false)
        ->assertSee('Consumo de metodos de emision individual');
});

// --- Regresiones de la emision (encontradas en la auditoria) ----------------

test('la fecha del XML es la misma que va dentro del CUF', function () {
    Queue::fake();
    $empresa = empresaListaParaFacturar();
    $caso = CasoPrueba::where('etapa', 4)->where('orden', 1)->sole();

    $facturacion = Mockery::mock(ServicioFacturacion::class);
    $facturacion->shouldReceive('recepcionarFactura')->andReturn(recepcion('908'));
    facturacionSimulada($facturacion);

    $this->post(route('admin.pruebas.caso', [$empresa, $caso]));

    $factura = Factura::sole();
    preg_match('/<fechaEmision>([^<]+)<\/fechaEmision>/', $factura->xml_firmado, $m);
    $fechaXml = preg_replace('/\D/', '', $m[1]);           // AAAAMMDDHHIISSmmm

    // El CUF es base16 de los 54 digitos + codigo de control: se reconvierte
    // y la fecha ocupa los digitos 14 a 30 (despues del NIT de 13).
    $hex = substr($factura->cuf, 0, strlen($factura->cuf) - strlen($factura->cufd->codigo_control));
    $decimal = str_pad(hexADecimal($hex), 54, '0', STR_PAD_LEFT);

    expect(substr($decimal, 13, 17))->toBe($fechaXml);
});

/**
 * Hexadecimal a decimal sin gmp ni bcmath: el CUF no entra en un entero.
 */
function hexADecimal(string $hex): string
{
    $decimal = '0';

    foreach (str_split($hex) as $digito) {
        // decimal = decimal * 16 + digito, sobre la cadena.
        $acarreo = hexdec($digito);
        $resultado = '';

        for ($i = strlen($decimal) - 1; $i >= 0; $i--) {
            $valor = ((int) $decimal[$i]) * 16 + $acarreo;
            $resultado = ($valor % 10).$resultado;
            $acarreo = intdiv($valor, 10);
        }

        $decimal = ltrim(($acarreo > 0 ? $acarreo : '').$resultado, '0') ?: '0';
    }

    return $decimal;
}

test('la firma del XML verifica en su lugar dentro del documento', function () {
    $certificado = Certificado::factory()->firmable()->create();
    $xml = '<?xml version="1.0" encoding="UTF-8"?><facturaElectronicaCompraVenta xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        .'<cabecera><a>1</a><b xsi:nil="true"/></cabecera></facturaElectronicaCompraVenta>';

    $firmado = app(FirmadorXml::class)->firmar($xml, $certificado);

    $doc = new DOMDocument;
    $doc->loadXML($firmado);
    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

    // Asi canonicaliza un verificador: el SignedInfo EN el documento, con los
    // namespaces que hereda de la raiz.
    $signedInfo = $xpath->query('//ds:SignedInfo')->item(0)->C14N();
    $firma = base64_decode($xpath->query('//ds:SignatureValue')->item(0)->textContent);
    $cert = "-----BEGIN CERTIFICATE-----\n"
        .chunk_split($xpath->query('//ds:X509Certificate')->item(0)->textContent, 64, "\n")
        ."-----END CERTIFICATE-----\n";

    expect(openssl_verify($signedInfo, $firma, openssl_pkey_get_public($cert), OPENSSL_ALGO_SHA256))->toBe(1);
});

// --- Etapa V: eventos significativos ----------------------------------------

/**
 * Empresa con el PV 1 y DOS CUFD vigentes: el viejo va como cufdEvento.
 *
 * @return array{0: Empresa, 1: Cufd, 2: Cufd} empresa, CUFD anterior, CUFD actual
 */
function empresaConDosCufd(): array
{
    $empresa = empresaConCuisEnPv1();
    $pv1 = PuntoVenta::where('codigo_punto_venta', 1)->sole();
    $anterior = Cufd::factory()->create(['punto_venta_id' => $pv1->id, 'codigo' => 'CUFD-VIEJO']);
    $actual = Cufd::factory()->create(['punto_venta_id' => $pv1->id, 'codigo' => 'CUFD-NUEVO']);

    // Los CUFD ya llevan una hora emitidos: hay ventana para ubicar eventos
    // despues de su emision, como exige el SIN.
    test()->travel(1)->hours();

    return [$empresa, $anterior, $actual];
}

function eventoAceptado(int $codigo = 1234): object
{
    return (object) ['RespuestaListaEventos' => (object) [
        'codigoRecepcionEventoSignificativo' => $codigo,
        'transaccion' => true,
    ]];
}

test('el seeder carga la etapa V: 7 motivos x 2 puntos de venta, 5 cada uno', function () {
    $casos = CasoPrueba::where('etapa', 5)->orderBy('orden')->get();

    expect($casos)->toHaveCount(14)
        ->and($casos->pluck('pruebas_esperadas')->unique()->all())->toBe([5])
        ->and($casos->pluck('payload_ejemplo.codigoMotivoEvento')->unique()->values()->all())->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and($casos[0]->payload_ejemplo['codigoPuntoVenta'])->toBe(1)
        ->and($casos[1]->payload_ejemplo['codigoPuntoVenta'])->toBe(0);
});

test('un evento va con el CUFD vigente, el anterior como cufdEvento y un rango ya terminado', function () {
    [$empresa, $anterior] = empresaConDosCufd();
    $caso = CasoPrueba::where('etapa', 5)->where('orden', 1)->sole();

    $operaciones = Mockery::mock(ServicioOperaciones::class);
    $operaciones->shouldReceive('registrarEvento')->once()
        ->with(Mockery::on(function (array $datos) use ($anterior): bool {
            $inicio = Carbon\Carbon::parse($datos['fechaHoraInicioEvento']);
            $fin = Carbon\Carbon::parse($datos['fechaHoraFinEvento']);

            return $datos['cufd'] === 'CUFD-NUEVO'
                && $datos['cufdEvento'] === 'CUFD-VIEJO'
                && $datos['cuis'] === 'CUIS-PV1'
                && $datos['codigoMotivoEvento'] === 1
                && $datos['codigoPuntoVenta'] === 1
                && $inicio->diffInMinutes($fin) == 1
                && $inicio->greaterThan($anterior->created_at)
                && $fin->lessThanOrEqualTo(now()->subMinutes(2));
        }))
        ->andReturn(eventoAceptado(555));
    codigosSimulados(Mockery::mock(ServicioCodigos::class), $operaciones);

    $this->post(route('admin.pruebas.caso', [$empresa, $caso]));

    $ejecucion = EjecucionPrueba::sole();
    expect($ejecucion->estado)->toBe(EjecucionPrueba::ESTADO_EXITOSO)
        ->and($ejecucion->respuesta['codigoRecepcionEventoSignificativo'])->toBe(555);
});

test('los eventos de un mismo punto de venta no se pisan entre si', function () {
    [$empresa] = empresaConDosCufd();
    $caso = CasoPrueba::where('etapa', 5)->where('orden', 1)->sole();

    $rangos = [];
    $operaciones = Mockery::mock(ServicioOperaciones::class);
    $operaciones->shouldReceive('registrarEvento')->times(3)
        ->andReturnUsing(function (array $datos) use (&$rangos) {
            $rangos[] = [$datos['fechaHoraInicioEvento'], $datos['fechaHoraFinEvento']];

            return eventoAceptado();
        });
    codigosSimulados(Mockery::mock(ServicioCodigos::class), $operaciones);

    foreach (range(1, 3) as $i) {
        $this->post(route('admin.pruebas.caso', [$empresa, $caso]));
    }

    // Van hacia adelante: cada uno empieza despues de que termina el anterior.
    expect($rangos[1][0] > $rangos[0][1])->toBeTrue()
        ->and($rangos[2][0] > $rangos[1][1])->toBeTrue();
});

test('ningun evento empieza antes de la emision del CUFD del evento (error 984 del SIN)', function () {
    [$empresa, $anterior] = empresaConDosCufd();
    $caso = CasoPrueba::where('etapa', 5)->where('orden', 1)->sole();

    $inicios = [];
    $operaciones = Mockery::mock(ServicioOperaciones::class);
    $operaciones->shouldReceive('registrarEvento')
        ->andReturnUsing(function (array $datos) use (&$inicios) {
            $inicios[] = Carbon\Carbon::parse($datos['fechaHoraInicioEvento']);

            return eventoAceptado();
        });
    codigosSimulados(Mockery::mock(ServicioCodigos::class), $operaciones);

    // Antes, a partir del ~20 los rangos cruzaban hacia atras la emision.
    foreach (range(1, 30) as $i) {
        $this->post(route('admin.pruebas.caso', [$empresa, $caso]));
    }

    expect($inicios)->toHaveCount(30);

    foreach ($inicios as $inicio) {
        expect($inicio->greaterThan($anterior->created_at))->toBeTrue();
    }
});

test('rellena el hueco entre la emision del CUFD y los eventos ya registrados', function () {
    [$empresa, $anterior] = empresaConDosCufd();
    $caso = CasoPrueba::where('etapa', 5)->where('orden', 1)->sole();

    // Un evento viejo ocupa el medio de la ventana.
    $ocupadoDesde = $anterior->created_at->copy()->addMinutes(30);
    EjecucionPrueba::create(['empresa_id' => $empresa->id, 'caso_id' => $caso->id, 'estado' => EjecucionPrueba::ESTADO_EXITOSO,
        'respuesta' => ['inicio' => $ocupadoDesde->toDateTimeString(), 'fin' => $ocupadoDesde->copy()->addMinute()->toDateTimeString()],
        'ejecutado_en' => now()]);

    $operaciones = Mockery::mock(ServicioOperaciones::class);
    $operaciones->shouldReceive('registrarEvento')->once()
        ->with(Mockery::on(fn (array $d) => Carbon\Carbon::parse($d['fechaHoraFinEvento'])->lessThan($ocupadoDesde)))
        ->andReturn(eventoAceptado());
    codigosSimulados(Mockery::mock(ServicioCodigos::class), $operaciones);

    $this->post(route('admin.pruebas.caso', [$empresa, $caso]));

    expect(EjecucionPrueba::latest('id')->first()->estado)->toBe(EjecucionPrueba::ESTADO_EXITOSO);
});

test('sin ventana de tiempo no llama al SIN y avisa cuanto esperar', function () {
    [$empresa] = empresaConDosCufd();
    // Vuelve al momento de la emision: ningun evento puede haber terminado.
    test()->travelBack();
    $caso = CasoPrueba::where('etapa', 5)->where('orden', 1)->sole();

    $operaciones = Mockery::mock(ServicioOperaciones::class);
    $operaciones->shouldNotReceive('registrarEvento');
    codigosSimulados(Mockery::mock(ServicioCodigos::class), $operaciones);

    $this->post(route('admin.pruebas.caso', [$empresa, $caso]));

    expect(EjecucionPrueba::sole()->respuesta['error'])->toContain('Reintenta en');
});

test('un evento rechazado por el SIN queda FALLIDO con su motivo', function () {
    [$empresa] = empresaConDosCufd();
    $caso = CasoPrueba::where('etapa', 5)->where('orden', 1)->sole();

    $operaciones = Mockery::mock(ServicioOperaciones::class);
    $operaciones->shouldReceive('registrarEvento')->andReturn((object) ['RespuestaListaEventos' => (object) [
        'transaccion' => false,
        'mensajesList' => (object) ['codigo' => 2001, 'descripcion' => 'FECHAS DEL EVENTO FUERA DE RANGO'],
    ]]);
    codigosSimulados(Mockery::mock(ServicioCodigos::class), $operaciones);

    $this->post(route('admin.pruebas.caso', [$empresa, $caso]));

    expect(EjecucionPrueba::sole()->respuesta['error'])->toContain('FUERA DE RANGO');
});

test('sin CUFD anterior se pide uno nuevo y el que habia pasa a ser el del evento', function () {
    $empresa = empresaConCuisEnPv1();
    $pv1 = PuntoVenta::where('codigo_punto_venta', 1)->sole();
    Cufd::factory()->create(['punto_venta_id' => $pv1->id, 'codigo' => 'CUFD-UNICO']);
    $this->travel(1)->hours();
    $caso = CasoPrueba::where('etapa', 5)->where('orden', 1)->sole();

    $codigos = Mockery::mock(ServicioCodigos::class);
    $codigos->shouldReceive('solicitarCufd')->once()->andReturn(respuestaCufd('CUFD-ROTADO'));

    $operaciones = Mockery::mock(ServicioOperaciones::class);
    $operaciones->shouldReceive('registrarEvento')->once()
        ->with(Mockery::on(fn (array $d) => $d['cufd'] === 'CUFD-ROTADO' && $d['cufdEvento'] === 'CUFD-UNICO'))
        ->andReturn(eventoAceptado());
    codigosSimulados($codigos, $operaciones);

    $this->post(route('admin.pruebas.caso', [$empresa, $caso]));

    expect(EjecucionPrueba::sole()->estado)->toBe(EjecucionPrueba::ESTADO_EXITOSO);
});

test('la etapa V va a la cola: 70 llamadas', function () {
    Queue::fake();
    [$empresa] = empresaConDosCufd();

    $this->post(route('admin.pruebas.etapa', [$empresa, 5]));

    Queue::assertPushed(EjecutarCasoPrueba::class, 70);
});

// --- Etapa VI: emision de paquetes ------------------------------------------

/**
 * Empresa lista para facturar por el PV 1 y con dos CUFD (el viejo va como
 * CUFD del evento).
 */
function empresaListaParaPaquetes(): Empresa
{
    $empresa = empresaListaParaFacturar();
    $pv1 = PuntoVenta::where('codigo_punto_venta', 1)->sole();
    Cufd::factory()->create(['punto_venta_id' => $pv1->id, 'codigo' => 'CUFD-ACTUAL', 'codigo_control' => 'CTRLACT']);

    // Ventana para el evento despues de la emision del CUFD del evento.
    test()->travel(1)->hours();

    return $empresa;
}

/**
 * Lee los nombres y contenidos de un TAR (sin gzip).
 *
 * @return array<string, string>
 */
function leerTar(string $tar): array
{
    $archivos = [];

    for ($pos = 0; $pos + 512 <= strlen($tar);) {
        $cabecera = substr($tar, $pos, 512);
        $nombre = rtrim(substr($cabecera, 0, 100), "\0");

        if ($nombre === '') {
            break;
        }

        $tamanio = octdec(rtrim(substr($cabecera, 124, 12), "\0 "));
        $archivos[$nombre] = substr($tar, $pos + 512, $tamanio);
        $pos += 512 + (int) ceil($tamanio / 512) * 512;
    }

    return $archivos;
}

test('el seeder carga la etapa VI: 14 envios de paquete y 2 validaciones', function () {
    $casos = CasoPrueba::where('etapa', 6)->orderBy('orden')->get();

    expect($casos)->toHaveCount(16)
        ->and($casos[0]->payload_ejemplo['cantidadFacturas'])->toBe(500)
        ->and($casos[0]->payload_ejemplo['codigoPuntoVenta'])->toBe(1)
        ->and($casos[1]->payload_ejemplo['cantidadFacturas'])->toBeLessThan(500)
        ->and($casos[1]->payload_ejemplo['codigoPuntoVenta'])->toBe(0)
        ->and($casos->take(14)->pluck('pruebas_esperadas')->unique()->all())->toBe([10])
        ->and($casos[14]->tipo)->toBe('validacionPaquete')
        ->and($casos[14]->pruebas_esperadas)->toBe(70)
        ->and($casos[15]->payload_ejemplo['codigoPuntoVenta'])->toBe(0);
});

test('un paquete lleva el evento registrado y un TAR de facturas firmadas fuera de linea', function () {
    $empresa = empresaListaParaPaquetes();
    $caso = CasoPrueba::where('etapa', 6)->where('orden', 1)->sole();
    $caso->update(['payload_ejemplo' => [...$caso->payload_ejemplo, 'cantidadFacturas' => 3]]);

    $operaciones = Mockery::mock(ServicioOperaciones::class);
    $operaciones->shouldReceive('registrarEvento')->once()->andReturn(eventoAceptado(777));

    $enviado = null;
    $facturacion = Mockery::mock(ServicioFacturacion::class);
    $facturacion->shouldReceive('recepcionarPaquete')->once()
        ->andReturnUsing(function (array $datos, string $tar) use (&$enviado) {
            $enviado = [$datos, $tar];

            return recepcion('901');
        });

    test()->mock(FabricaServicios::class, function ($mock) use ($operaciones, $facturacion) {
        $mock->shouldReceive('operaciones')->andReturn($operaciones);
        $mock->shouldReceive('facturacion')->andReturn($facturacion);
    });

    $this->post(route('admin.pruebas.caso', [$empresa, $caso]));

    $ejecucion = EjecucionPrueba::sole();
    expect($ejecucion->estado)->toBe(EjecucionPrueba::ESTADO_EXITOSO)
        ->and($ejecucion->respuesta['codigoRecepcion'])->toBe('REC-1');

    [$datos, $tar] = $enviado;
    expect($datos)
        ->codigoEvento->toBe(777)
        ->cantidadFacturas->toBe(3)
        ->codigoEmision->toBe(2)
        ->cufd->toBe('CUFD-ACTUAL');

    $xmls = leerTar($tar);
    expect($xmls)->toHaveCount(3);

    foreach ($xmls as $xml) {
        // Facturas de contingencia: CUFD del evento, firmadas.
        expect($xml)->toContain('<cufd>'.$ejecucion->respuesta['cufdEvento'].'</cufd>')
            ->toContain('SignatureValue');
    }

    // No se guardan como facturas: solo consumen el correlativo.
    expect(Factura::count())->toBe(0)
        ->and(PuntoVenta::where('codigo_punto_venta', 1)->sole()->siguiente_factura)->toBe(4);
});

test('el CUF de una factura del paquete codifica tipo de emision 2', function () {
    $empresa = empresaListaParaPaquetes();
    $pv1 = PuntoVenta::where('codigo_punto_venta', 1)->sole();
    $cufd = $pv1->cufds()->orderBy('id')->first();

    $paquete = app(GeneradorPaquetePrueba::class)->armar(
        $empresa, $pv1, $cufd,
        ['comprador' => ['tipo_documento' => 1, 'numero_documento' => '1', 'razon_social' => 'X'], 'metodo_pago' => 1,
            'items' => [['codigo_producto_sin' => 83141, 'descripcion' => 'X', 'cantidad' => 1, 'unidad_medida' => 58, 'precio_unitario' => 10]]],
        1, now()->subHour()->startOfSecond(),
    );

    $cuf = array_key_first(leerTar($paquete['tar']));
    $hex = substr(basename($cuf, '.xml'), 0, -strlen($cufd->codigo_control));
    $decimal = str_pad(hexADecimal($hex), 54, '0', STR_PAD_LEFT);

    // NIT(13) fecha(17) sucursal(4) modalidad(1) -> el digito 36 es el tipo de emision.
    expect($decimal[35])->toBe('2');
});

test('la validacion toma el paquete enviado sin validar y cuenta solo con 908', function () {
    $empresa = empresaListaParaPaquetes();
    $envio = CasoPrueba::where('etapa', 6)->where('orden', 1)->sole();
    $validacion = CasoPrueba::where('etapa', 6)->where('tipo', 'validacionPaquete')
        ->get()->first(fn ($c) => $c->payload_ejemplo['codigoPuntoVenta'] === 1);

    foreach (['PAQ-1', 'PAQ-2'] as $codigo) {
        EjecucionPrueba::create(['empresa_id' => $empresa->id, 'caso_id' => $envio->id, 'estado' => EjecucionPrueba::ESTADO_EXITOSO,
            'respuesta' => ['codigoRecepcion' => $codigo, 'inicio' => now()->subHour()->toDateTimeString()], 'ejecutado_en' => now()]);
    }

    $pedidos = [];
    $facturacion = Mockery::mock(ServicioFacturacion::class);
    $facturacion->shouldReceive('validarRecepcionPaquete')->times(3)
        ->andReturnUsing(function (array $datos) use (&$pedidos) {
            $pedidos[] = $datos['codigoRecepcion'];

            // El primero todavia en proceso; despues, validados.
            return recepcion(count($pedidos) === 1 ? '901' : '908');
        });
    facturacionSimulada($facturacion);

    foreach (range(1, 3) as $i) {
        $this->post(route('admin.pruebas.caso', [$empresa, $validacion]));
    }

    // 901 no consume el paquete: se vuelve a preguntar por PAQ-1.
    expect($pedidos)->toBe(['PAQ-1', 'PAQ-1', 'PAQ-2'])
        ->and(EjecucionPrueba::where('caso_id', $validacion->id)->where('estado', EjecucionPrueba::ESTADO_EXITOSO)->count())->toBe(2);
});

test('el archivo del paquete es un TAR valido', function () {
    $tar = app(ArmadorPaquete::class)->tar(['a.xml' => '<a/>', 'b.xml' => str_repeat('x', 700)]);

    expect(leerTar($tar))->toBe(['a.xml' => '<a/>', 'b.xml' => str_repeat('x', 700)])
        ->and(strlen($tar) % 512)->toBe(0);
});

test('una etapa bloqueada dice por que', function () {
    $empresa = empresaDeEtapaUno(); // sin certificado

    $this->get(route('admin.pruebas.show', $empresa))
        ->assertSee('esta etapa firma facturas y la empresa no tiene certificado .p12 activo');
});

// --- Etapa VII: anulacion ----------------------------------------------------

test('el seeder carga la etapa VII: 125 anulaciones por punto de venta', function () {
    $casos = CasoPrueba::where('etapa', 7)->orderBy('orden')->get();

    expect($casos)->toHaveCount(2)
        ->and($casos->pluck('payload_ejemplo.codigoPuntoVenta')->all())->toBe([1, 0])
        ->and($casos->pluck('pruebas_esperadas')->all())->toBe([125, 125])
        ->and($casos[0]->requiereCertificado())->toBeFalse();
});

test('una anulacion toma la factura validada mas vieja y la anula con el CUFD vigente', function () {
    Queue::fake();
    $empresa = empresaListaParaFacturar();
    $pv1 = PuntoVenta::where('codigo_punto_venta', 1)->sole();
    Catalogo::factory()->create(['tipo' => 'motivos_anulacion', 'codigo_clasificador' => '1', 'descripcion' => 'FACTURA MAL EMITIDA']);
    $vieja = Factura::factory()->create(['empresa_id' => $empresa->id, 'punto_venta_id' => $pv1->id, 'estado' => Factura::ESTADO_VALIDADA]);
    Factura::factory()->create(['empresa_id' => $empresa->id, 'punto_venta_id' => $pv1->id, 'estado' => Factura::ESTADO_VALIDADA]);
    $vigente = $pv1->cufdVigente();
    $caso = CasoPrueba::where('etapa', 7)->where('orden', 1)->sole();

    $facturacion = Mockery::mock(ServicioFacturacion::class);
    $facturacion->shouldReceive('anular')->once()
        ->with(Mockery::on(fn (Factura $f) => $f->is($vieja)), 1, $vigente->codigo, 'CUIS-PV1')
        ->andReturn(recepcion('905'));
    facturacionSimulada($facturacion);

    $this->post(route('admin.pruebas.caso', [$empresa, $caso]));

    expect(EjecucionPrueba::sole()->estado)->toBe(EjecucionPrueba::ESTADO_EXITOSO)
        ->and($vieja->fresh()->estado)->toBe(Factura::ESTADO_ANULADA)
        ->and($vieja->anulacion->estado)->toBe(FacturaAnulada::ESTADO_CONFIRMADA);
});

test('sin facturas validadas la anulacion falla sin llamar al SIN', function () {
    $empresa = empresaListaParaFacturar();
    $caso = CasoPrueba::where('etapa', 7)->where('orden', 1)->sole();

    $facturacion = Mockery::mock(ServicioFacturacion::class);
    $facturacion->shouldNotReceive('anular');
    facturacionSimulada($facturacion);

    $this->post(route('admin.pruebas.caso', [$empresa, $caso]));

    expect(EjecucionPrueba::sole()->respuesta['error'])->toContain('corre antes la etapa IV');
});

test('la anulacion de produccion manda la cabecera completa del WSDL', function () {
    $empresa = empresaListaParaFacturar();
    $pv1 = PuntoVenta::where('codigo_punto_venta', 1)->sole();
    $factura = Factura::factory()->create(['empresa_id' => $empresa->id, 'punto_venta_id' => $pv1->id, 'tipo_emision' => 1]);

    $cliente = Mockery::mock(SiatClient::class);
    $cliente->shouldReceive('paraServicio')->andReturn(Mockery::mock(SoapClient::class));
    $cliente->shouldReceive('ultimaPeticionXml', 'ultimaRespuestaXml')->andReturn(null);
    $cliente->shouldReceive('llamar')->once()
        ->with(Mockery::any(), 'anulacionFactura', Mockery::on(function (array $p) {
            $s = $p['SolicitudServicioAnulacionFactura'];

            return $s['codigoEmision'] === 1 && $s['cuis'] === 'CUIS-X' && $s['tipoFacturaDocumento'] === 1
                && $s['cufd'] === 'CUFD-X' && $s['codigoMotivo'] === 1;
        }))
        ->andReturn(recepcion('905'));

    (new ServicioFacturacion($empresa, $cliente))->anular($factura, 1, 'CUFD-X', 'CUIS-X');
});

// --- Etapa VIII: firma digital ----------------------------------------------

test('el seeder carga la etapa VIII: 115 facturas firmadas por punto de venta', function () {
    $casos = CasoPrueba::where('etapa', 8)->orderBy('orden')->get();

    expect($casos)->toHaveCount(2)
        ->and($casos->pluck('tipo')->unique()->all())->toBe(['emisionIndividual'])
        ->and($casos->pluck('payload_ejemplo.codigoPuntoVenta')->all())->toBe([1, 0])
        ->and($casos->pluck('pruebas_esperadas')->all())->toBe([115, 115])
        ->and($casos[0]->requiereCertificado())->toBeTrue();
});

// --- Etapa XI: reversion de anulacion ----------------------------------------

test('el seeder carga la etapa XI: 125 reversiones por punto de venta', function () {
    $casos = CasoPrueba::where('etapa', 11)->orderBy('orden')->get();

    expect($casos)->toHaveCount(2)
        ->and($casos->pluck('payload_ejemplo.codigoPuntoVenta')->all())->toBe([1, 0])
        ->and($casos->pluck('pruebas_esperadas')->all())->toBe([125, 125]);
});

test('una reversion toma la anulada mas vieja y la devuelve a VALIDADA', function () {
    $empresa = empresaListaParaFacturar();
    $pv1 = PuntoVenta::where('codigo_punto_venta', 1)->sole();
    $factura = Factura::factory()->create(['empresa_id' => $empresa->id, 'punto_venta_id' => $pv1->id, 'estado' => Factura::ESTADO_ANULADA]);
    FacturaAnulada::create(['factura_id' => $factura->id, 'motivo' => 1, 'anulada_en' => now(),
        'estado' => FacturaAnulada::ESTADO_CONFIRMADA, 'estado_anterior' => Factura::ESTADO_VALIDADA]);
    $caso = CasoPrueba::where('etapa', 11)->where('orden', 1)->sole();

    $facturacion = Mockery::mock(ServicioFacturacion::class);
    $facturacion->shouldReceive('revertirAnulacion')->once()
        ->with(Mockery::on(fn (Factura $f) => $f->is($factura)), $pv1->cufdVigente()->codigo, 'CUIS-PV1')
        ->andReturn(recepcion('907'));
    facturacionSimulada($facturacion);

    $this->post(route('admin.pruebas.caso', [$empresa, $caso]));

    expect(EjecucionPrueba::sole()->estado)->toBe(EjecucionPrueba::ESTADO_EXITOSO)
        ->and($factura->fresh()->estado)->toBe(Factura::ESTADO_VALIDADA)
        ->and($factura->anulacion->fresh()->estado)->toBe(FacturaAnulada::ESTADO_REVERTIDA);
});

test('sin anuladas confirmadas la reversion falla sin llamar al SIN', function () {
    $empresa = empresaListaParaFacturar();
    $caso = CasoPrueba::where('etapa', 11)->where('orden', 1)->sole();

    $facturacion = Mockery::mock(ServicioFacturacion::class);
    $facturacion->shouldNotReceive('revertirAnulacion');
    facturacionSimulada($facturacion);

    $this->post(route('admin.pruebas.caso', [$empresa, $caso]));

    expect(EjecucionPrueba::sole()->respuesta['error'])->toContain('corre antes la etapa VII');
});

test('la vista muestra la etapa XI con su numero romano', function () {
    $this->get(route('admin.pruebas.show', empresaDeEtapaUno()))
        ->assertSee('Etapa XI — Reversion', false);
});

// --- Limpiar pruebas ---------------------------------------------------------

test('limpiar una etapa borra solo sus ejecuciones y sus jobs en cola', function () {
    config(['queue.default' => 'database']);
    $empresa = empresaConCuisEnPv1();
    $etapa2 = CasoPrueba::where('etapa', 2)->first();
    $etapa1 = CasoPrueba::where('etapa', 1)->first();

    foreach ([$etapa1, $etapa2] as $caso) {
        EjecucionPrueba::create(['empresa_id' => $empresa->id, 'caso_id' => $caso->id,
            'estado' => EjecucionPrueba::ESTADO_EXITOSO, 'ejecutado_en' => now()]);
        EjecutarCasoPrueba::dispatch($empresa->id, $caso->id);
    }

    $this->delete(route('admin.pruebas.limpiar', $empresa), ['etapa' => 2])
        ->assertSessionHas('estado', fn (string $m) => str_contains($m, '1 ejecucion(es) borradas y 1 job(s)'));

    // La etapa I queda intacta, con su job.
    expect(EjecucionPrueba::pluck('caso_id')->all())->toBe([$etapa1->id])
        ->and(DB::table('jobs')->count())->toBe(1);
});

test('limpiar todas borra las ejecuciones de todas las etapas, no las facturas', function () {
    $empresa = empresaListaParaFacturar();
    $pv1 = PuntoVenta::where('codigo_punto_venta', 1)->sole();
    Factura::factory()->create(['empresa_id' => $empresa->id, 'punto_venta_id' => $pv1->id]);

    foreach (CasoPrueba::whereNotNull('etapa')->take(3)->get() as $caso) {
        EjecucionPrueba::create(['empresa_id' => $empresa->id, 'caso_id' => $caso->id,
            'estado' => EjecucionPrueba::ESTADO_EXITOSO, 'ejecutado_en' => now()]);
    }

    $this->delete(route('admin.pruebas.limpiar', $empresa))->assertRedirect();

    expect(EjecucionPrueba::count())->toBe(0)
        ->and(Factura::count())->toBe(1)
        ->and(Cuis::count())->toBe(1);
});

test('la vista muestra los botones de limpiar', function () {
    $this->get(route('admin.pruebas.show', empresaDeEtapaUno()))
        ->assertSee('Limpiar todas')
        ->assertSee('name="etapa" value="1"', false);
});

// --- CUIS atado al CUFD ------------------------------------------------------

test('la factura viaja con el CUIS que genero su CUFD, no con uno mas nuevo (913 del SIN)', function () {
    Queue::fake();
    $empresa = empresaListaParaFacturar();      // CUFD emitido con CUIS-PV1
    $pv1 = PuntoVenta::where('codigo_punto_venta', 1)->sole();
    Cuis::factory()->create(['punto_venta_id' => $pv1->id, 'codigo' => 'CUIS-MAS-NUEVO']);
    $caso = CasoPrueba::where('etapa', 4)->where('orden', 1)->sole();

    $facturacion = Mockery::mock(ServicioFacturacion::class);
    $facturacion->shouldReceive('recepcionarFactura')->once()
        ->with(Mockery::type(Factura::class), Mockery::any(), 'CUIS-PV1')
        ->andReturn(recepcion('908'));
    facturacionSimulada($facturacion);

    $this->post(route('admin.pruebas.caso', [$empresa, $caso]));

    expect(EjecucionPrueba::sole()->estado)->toBe(EjecucionPrueba::ESTADO_EXITOSO);
});

test('cuisDe cae al CUIS vigente si el CUFD no tiene vinculo guardado', function () {
    $pv = PuntoVenta::factory()->create();
    $viejo = Cuis::factory()->create(['punto_venta_id' => $pv->id]);
    $nuevo = Cuis::factory()->create(['punto_venta_id' => $pv->id]);
    $conVinculo = Cufd::factory()->create(['punto_venta_id' => $pv->id, 'cuis_id' => $viejo->id]);
    $sinVinculo = Cufd::factory()->create(['punto_venta_id' => $pv->id, 'cuis_id' => null]);

    expect($pv->cuisDe($conVinculo)->is($viejo))->toBeTrue()
        ->and($pv->cuisDe($sinVinculo)->is($nuevo))->toBeTrue();
});
