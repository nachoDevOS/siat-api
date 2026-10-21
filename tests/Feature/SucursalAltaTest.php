<?php

use App\Exceptions\SiatException;
use App\Models\CasoPrueba;
use App\Models\Cufd;
use App\Models\Cuis;
use App\Models\EjecucionPrueba;
use App\Models\Empresa;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\ServicioCodigos;
use App\Services\Siat\ServicioOperaciones;
use App\Services\Siat\SiatClient;
use Database\Seeders\CasosPruebaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    // La pausa tras cerrar operaciones no espera de verdad en las pruebas.
    Sleep::fake();
});

/**
 * Respuesta del SIN a un CUIS: nuevo (transaccion = true) o el que ya estaba
 * vigente (transaccion = false con el 980).
 */
function cuisDelAlta(string $codigo, bool $yaVigente = false): object
{
    $cuerpo = ['codigo' => $codigo, 'fechaVigencia' => '2027-10-19T10:00:00.000-04:00', 'transaccion' => ! $yaVigente];

    if ($yaVigente) {
        $cuerpo['mensajesList'] = (object) ['codigo' => 980, 'descripcion' => 'EXISTE UN CUIS VIGENTE PARA LA SUCURSAL O PUNTO DE VENTA'];
    }

    return (object) ['RespuestaCuis' => (object) $cuerpo];
}

function cierreAceptado(): object
{
    return (object) ['RespuestaCierreSistemas' => (object) ['codigoSistema' => 'X', 'transaccion' => true]];
}

function datosSucursal(): array
{
    return ['codigo_sucursal' => 0, 'nombre' => 'Casa Matriz', 'municipio' => 'Trinidad', 'direccion' => 'Av. 6 de Agosto', 'telefono' => '78743659'];
}

test('en el piloto el alta pide solo CUIS nuevos del PV 1 y del PV 0, por V2 y V3, y los deja contados en la etapa I', function () {
    $this->seed(CasosPruebaSeeder::class);
    $empresa = Empresa::factory()->create(['codigo_ambiente' => 2]);

    // Por punto de venta: V2 pregunta y encuentra un CUIS anterior (980),
    // cierra con ese y sale uno nuevo. V3 ya sabe cual es el vigente (el que
    // acaba de emitir V2): cierra directo, sin volver a preguntar.
    $codigosV2 = Mockery::mock(ServicioCodigos::class);
    $codigosV2->shouldReceive('solicitarCuisPara')->with(0, 1)->twice()
        ->andReturn(cuisDelAlta('VIEJO-PV1', yaVigente: true), cuisDelAlta('V2-PV1'));
    $codigosV2->shouldReceive('solicitarCuisPara')->with(0, 0)->twice()
        ->andReturn(cuisDelAlta('VIEJO-PV0', yaVigente: true), cuisDelAlta('V2-PV0'));
    $operacionesV2 = Mockery::mock(ServicioOperaciones::class);
    $operacionesV2->shouldReceive('cerrarOperacionesSistema')->once()->with(0, 1, 'VIEJO-PV1')->andReturn(cierreAceptado());
    $operacionesV2->shouldReceive('cerrarOperacionesSistema')->once()->with(0, 0, 'VIEJO-PV0')->andReturn(cierreAceptado());

    // En el PV 0 de V3 el primer pedido tras el cierre no responde: se reintenta.
    $codigosV3 = Mockery::mock(ServicioCodigos::class);
    $codigosV3->shouldReceive('solicitarCuisPara')->with(0, 1)->once()->andReturn(cuisDelAlta('V3-PV1'));
    $codigosV3->shouldReceive('solicitarCuisPara')->with(0, 0)->twice()->andReturnUsing(
        fn () => throw new SiatException('Error Fetching http headers'),
        fn () => cuisDelAlta('V3-PV0'),
    );
    // El cierre de las pruebas V3 tambien va por /v2: por /sfd el pedido
    // siguiente se cuelga (medido en el piloto el 20/10/2026).
    $operacionesV2->shouldReceive('cerrarOperacionesSistema')->once()->with(0, 1, 'V2-PV1')->andReturn(cierreAceptado());
    $operacionesV2->shouldReceive('cerrarOperacionesSistema')->once()->with(0, 0, 'V2-PV0')->andReturn(cierreAceptado());
    $operacionesV3 = Mockery::mock(ServicioOperaciones::class);
    $operacionesV3->shouldNotReceive('cerrarOperacionesSistema');

    // Al abrir la ficha se consulta el SIN; aca simula que no responde.
    $operacionesV3->shouldReceive('consultarPuntosVenta')->andThrow(new SiatException('sin red'));

    // Solo CUIS: ningun CUFD.
    $codigosV2->shouldNotReceive('solicitarCufd');
    $codigosV3->shouldNotReceive('solicitarCufd');

    $this->mock(FabricaServicios::class, function ($mock) use ($codigosV2, $codigosV3, $operacionesV2, $operacionesV3) {
        $mock->shouldReceive('codigos')->with(Mockery::any(), SiatClient::VERSION_V2)->andReturn($codigosV2);
        $mock->shouldReceive('codigos')->andReturn($codigosV3);
        $mock->shouldReceive('operaciones')->with(Mockery::any(), SiatClient::VERSION_V2)->andReturn($operacionesV2);
        $mock->shouldReceive('operaciones')->andReturn($operacionesV3);
    });

    $this->post(route('admin.empresas.sucursales.store', $empresa), datosSucursal())
        ->assertRedirect(route('admin.empresas.show', $empresa))
        ->assertSessionHas('estado', fn (string $m) => str_ends_with($m, 'CUIS de la etapa I (original y V.3): 4 de 4 contados.'));

    // La ficha muestra el resumen por punto de venta y version, sin Reintentar.
    $this->get(route('admin.empresas.show', $empresa))
        ->assertOk()
        ->assertSeeInOrder(['CUIS de la etapa I', '4 de 4 contadas', 'PV 1', 'V2', 'V2-PV1', 'V3', 'V3-PV1', 'PV 0', 'V2', 'V2-PV0', 'V3', 'V3-PV0'])
        ->assertDontSee('Reintentar');

    // Las 4 pruebas de la etapa I quedan contadas.
    $contadas = CasoPrueba::whereIn('id', EjecucionPrueba::where('estado', EjecucionPrueba::ESTADO_EXITOSO)->pluck('caso_id'))->get();
    expect($contadas)->toHaveCount(4)
        ->and($contadas->pluck('etapa')->unique()->sort()->values()->all())->toBe([1, 101])
        // El 980 de la consulta no queda como prueba fallida: el unico FALLIDO
        // es el timeout real del PV 0 en V3.
        ->and(EjecucionPrueba::where('estado', EjecucionPrueba::ESTADO_FALLIDO)->count())->toBe(1);

    // El PV 1 se configuro solo; los dos quedan con el CUIS de V3 vigente, y
    // cada CUIS conserva la version que lo emitio aunque la otra lo devolviera.
    $pv0 = PuntoVenta::where('codigo_punto_venta', 0)->sole();
    $pv1 = PuntoVenta::where('codigo_punto_venta', 1)->sole();
    expect($pv0->cuisVigente()->codigo)->toBe('V3-PV0')
        ->and($pv1->cuisVigente()->codigo)->toBe('V3-PV1')
        ->and(Cuis::pluck('version', 'codigo')->only(['V2-PV1', 'V3-PV1', 'V2-PV0', 'V3-PV0'])->all())
        ->toEqual(['V2-PV1' => 'v2', 'V3-PV1' => 'v3', 'V2-PV0' => 'v2', 'V3-PV0' => 'v3'])
        ->and(Cufd::count())->toBe(0);
});

test('sin pruebas de etapa I, el alta en el piloto pide solo CUIS por V2 y por V3', function () {
    $empresa = Empresa::factory()->create(['codigo_ambiente' => 2]);

    $codigosV2 = Mockery::mock(ServicioCodigos::class);
    $codigosV2->shouldReceive('solicitarCuis')->twice()->andReturn(cuisDelAlta('VIEJO', yaVigente: true), cuisDelAlta('CUIS-V2'));
    $operacionesV2 = Mockery::mock(ServicioOperaciones::class);
    $operacionesV2->shouldReceive('cerrarOperacionesSistema')->once()->with(0, 0, 'VIEJO')->andReturn(cierreAceptado());

    $codigosV3 = Mockery::mock(ServicioCodigos::class);
    $codigosV3->shouldReceive('solicitarCuis')->twice()->andReturn(cuisDelAlta('CUIS-V2', yaVigente: true), cuisDelAlta('CUIS-V3'));
    // Tambien el cierre de V3 va por /v2.
    $operacionesV2->shouldReceive('cerrarOperacionesSistema')->once()->with(0, 0, 'CUIS-V2')->andReturn(cierreAceptado());
    $operacionesV3 = Mockery::mock(ServicioOperaciones::class);
    $operacionesV3->shouldNotReceive('cerrarOperacionesSistema');

    $codigosV2->shouldNotReceive('solicitarCufd');
    $codigosV3->shouldNotReceive('solicitarCufd');

    $this->mock(FabricaServicios::class, function ($mock) use ($codigosV2, $codigosV3, $operacionesV2, $operacionesV3) {
        $mock->shouldReceive('codigos')->with(Mockery::any(), SiatClient::VERSION_V2)->andReturn($codigosV2);
        $mock->shouldReceive('codigos')->with(Mockery::any(), SiatClient::VERSION_V3)->andReturn($codigosV3);
        $mock->shouldReceive('operaciones')->with(Mockery::any(), SiatClient::VERSION_V2)->andReturn($operacionesV2);
        $mock->shouldReceive('operaciones')->with(Mockery::any(), SiatClient::VERSION_V3)->andReturn($operacionesV3);
    });

    $this->post(route('admin.empresas.sucursales.store', $empresa), datosSucursal())
        ->assertSessionHas('estado', fn (string $m) => str_contains($m, 'V2: CUIS nuevo CUIS-V2. V3: CUIS nuevo CUIS-V3.'));

    $puntoVenta = PuntoVenta::sole();

    expect(Cuis::pluck('version', 'codigo')->only(['CUIS-V2', 'CUIS-V3'])->all())->toEqual(['CUIS-V2' => 'v2', 'CUIS-V3' => 'v3'])
        // El anterior que devolvio el 980 queda guardado y cerrado.
        ->and(Cuis::where('codigo', 'VIEJO')->sole()->cerrado_en)->not->toBeNull()
        ->and($puntoVenta->cuisVigente()->codigo)->toBe('CUIS-V3')
        ->and(Cufd::count())->toBe(0);
});

test('en produccion el alta de sucursal solo usa la V3 y no cierra nada si el CUIS es nuevo', function () {
    $empresa = Empresa::factory()->create(['codigo_ambiente' => 1]);

    $codigosV3 = Mockery::mock(ServicioCodigos::class);
    $codigosV3->shouldReceive('solicitarCuis')->once()->andReturn(cuisDelAlta('CUIS-V3'));
    $codigosV3->shouldNotReceive('solicitarCufd');

    $this->mock(FabricaServicios::class, function ($mock) use ($codigosV3) {
        $mock->shouldReceive('codigos')->with(Mockery::any(), SiatClient::VERSION_V3)->andReturn($codigosV3);
        $mock->shouldNotReceive('operaciones');
    });

    $this->post(route('admin.empresas.sucursales.store', $empresa), datosSucursal())
        ->assertSessionHas('estado', fn (string $m) => str_contains($m, 'V3: CUIS nuevo CUIS-V3.') && ! str_contains($m, 'V2'));

    expect(Cuis::sole()->version)->toBe('v3');
});

test('si el SIN no cierra las operaciones la sucursal igual queda creada con el motivo', function () {
    $empresa = Empresa::factory()->create(['codigo_ambiente' => 1]);

    $codigos = Mockery::mock(ServicioCodigos::class);
    $codigos->shouldReceive('solicitarCuis')->once()->andReturn(cuisDelAlta('VIEJO', yaVigente: true));
    $operaciones = Mockery::mock(ServicioOperaciones::class);
    $operaciones->shouldReceive('cerrarOperacionesSistema')->once()->andReturn((object) ['RespuestaCierreSistemas' => (object) [
        'transaccion' => false,
        'mensajesList' => (object) ['codigo' => 995, 'descripcion' => 'SERVICIO NO DISPONIBLE'],
    ]]);

    $this->mock(FabricaServicios::class, function ($mock) use ($codigos, $operaciones) {
        $mock->shouldReceive('codigos')->andReturn($codigos);
        $mock->shouldReceive('operaciones')->andReturn($operaciones);
    });

    $this->post(route('admin.empresas.sucursales.store', $empresa), datosSucursal())
        ->assertSessionHas('estado', fn (string $m) => str_contains($m, 'no se obtuvo su CUIS: V3: el SIN no cerro las operaciones del CUIS VIEJO'));

    // El que devolvio el 980 queda guardado y vigente: el SIN no lo cerro.
    expect(Sucursal::count())->toBe(1)
        ->and(PuntoVenta::sole()->cuisVigente()->codigo)->toBe('VIEJO');
});

test('si el SIN no responde al pedir el CUIS despues del cierre se reintenta hasta tres veces, con pausa', function () {
    $empresa = Empresa::factory()->create(['codigo_ambiente' => 1]);

    // Como en el piloto: tras el cierre por /sfd, los primeros pedidos se cuelgan.
    $codigos = Mockery::mock(ServicioCodigos::class);
    $codigos->shouldReceive('solicitarCuis')->times(4)->andReturnUsing(
        fn () => cuisDelAlta('VIEJO', yaVigente: true),
        fn () => throw new SiatException('Error Fetching http headers'),
        fn () => throw new SiatException('Error Fetching http headers'),
        fn () => cuisDelAlta('CUIS-NUEVO'),
    );
    $operaciones = Mockery::mock(ServicioOperaciones::class);
    $operaciones->shouldReceive('cerrarOperacionesSistema')->once()->andReturn(cierreAceptado());

    $this->mock(FabricaServicios::class, function ($mock) use ($codigos, $operaciones) {
        $mock->shouldReceive('codigos')->andReturn($codigos);
        $mock->shouldReceive('operaciones')->andReturn($operaciones);
    });

    $this->post(route('admin.empresas.sucursales.store', $empresa), datosSucursal())
        ->assertSessionHas('estado', fn (string $m) => str_contains($m, 'V3: CUIS nuevo CUIS-NUEVO.'));

    expect(PuntoVenta::sole()->cuisVigente()->codigo)->toBe('CUIS-NUEVO');
    // Una pausa de 2 s antes de cada uno de los tres pedidos.
    Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(2)->seconds(), Sleep::for(2)->seconds()]);
});

test('si tras el cierre el SIN no responde, el CUIS cerrado no queda vigente en la base', function () {
    $this->seed(CasosPruebaSeeder::class);
    $empresa = Empresa::factory()->create(['codigo_ambiente' => 2]);

    // Todas las versiones y puntos de venta: el SIN devuelve el vigente, se
    // cierra, y desde ahi ningun pedido de CUIS de ese punto de venta responde.
    $cerrados = [];
    $codigos = Mockery::mock(ServicioCodigos::class);
    $codigos->shouldReceive('solicitarCuisPara')->andReturnUsing(function (int $sucursal, int $puntoVenta) use (&$cerrados) {
        return isset($cerrados[$puntoVenta])
            ? throw new SiatException('Error Fetching http headers')
            : cuisDelAlta("CERRADO-PV{$puntoVenta}", yaVigente: true);
    });
    $operaciones = Mockery::mock(ServicioOperaciones::class);
    $operaciones->shouldReceive('cerrarOperacionesSistema')->andReturnUsing(function (int $sucursal, int $puntoVenta) use (&$cerrados) {
        $cerrados[$puntoVenta] = true;

        return cierreAceptado();
    });

    $this->mock(FabricaServicios::class, function ($mock) use ($codigos, $operaciones) {
        $mock->shouldReceive('codigos')->andReturn($codigos);
        $mock->shouldReceive('operaciones')->andReturn($operaciones);
    });

    $this->post(route('admin.empresas.sucursales.store', $empresa), datosSucursal())
        ->assertSessionHas('estado', fn (string $m) => str_contains($m, 'CUIS de la etapa I (original y V.3): 0 de 4 contados.') && str_contains($m, 'Error Fetching http headers'));

    expect(PuntoVenta::where('codigo_punto_venta', 0)->sole()->cuisVigente())->toBeNull()
        ->and(PuntoVenta::where('codigo_punto_venta', 1)->sole()->cuisVigente())->toBeNull();
});

test('la ficha muestra la prueba de la etapa I que no conto con su motivo y Reintentar la completa', function () {
    $this->seed(CasosPruebaSeeder::class);
    $empresa = Empresa::factory()->create(['codigo_ambiente' => 2]);
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);
    $caso = CasoPrueba::where('etapa', 101)->where('payload_ejemplo->codigoPuntoVenta', 0)->sole();
    EjecucionPrueba::create([
        'empresa_id' => $empresa->id, 'caso_id' => $caso->id, 'estado' => EjecucionPrueba::ESTADO_FALLIDO,
        'respuesta' => ['error' => "Falla en la operacion 'cuis': Error Fetching http headers"], 'ejecutado_en' => now(),
    ]);

    // El SIN ya tenia uno vigente: Reintentar cierra y pide otro, sin CUFD.
    $codigos = Mockery::mock(ServicioCodigos::class);
    $codigos->shouldReceive('solicitarCuisPara')->with(0, 0)->twice()
        ->andReturn(cuisDelAlta('VIEJO', yaVigente: true), cuisDelAlta('CUIS-REINTENTO'));
    $codigos->shouldNotReceive('solicitarCufd');
    // La ficha consulta al SIN sus puntos de venta: aca no responde.
    $codigos->shouldReceive('solicitarCuisPara')->with(0)->andThrow(new SiatException('sin red'));
    $operaciones = Mockery::mock(ServicioOperaciones::class);
    $operaciones->shouldReceive('cerrarOperacionesSistema')->once()->with(0, 0, 'VIEJO')->andReturn(cierreAceptado());
    $operaciones->shouldReceive('consultarPuntosVenta')->andThrow(new SiatException('sin red'));

    $this->mock(FabricaServicios::class, function ($mock) use ($codigos, $operaciones) {
        $mock->shouldReceive('codigos')->andReturn($codigos);
        $mock->shouldReceive('operaciones')->andReturn($operaciones);
    });

    $ficha = route('admin.empresas.show', $empresa);

    $this->get($ficha)->assertOk()
        ->assertSeeInOrder(['CUIS de la etapa I', '0 de 4 contadas', 'PV 0', 'V3', 'Error Fetching http headers', 'Reintentar']);

    $this->from($ficha)->post(route('admin.pruebas.cuis-nuevo', [$empresa, $caso]))
        ->assertRedirect($ficha)
        ->assertSessionHas('estado', 'PV 0 V3: CUIS nuevo CUIS-REINTENTO, contado en la etapa I V.3.');

    $this->get($ficha)->assertSeeInOrder(['1 de 4 contadas', 'PV 0', 'V3', 'CUIS-REINTENTO', 'contada']);
});

test('no cierra un CUIS hasta que pasen 60 s desde que el SIN lo emitio, para que no repita el codigo', function () {
    $this->travelTo(now()->startOfSecond());
    $empresa = Empresa::factory()->create(['codigo_ambiente' => 2]);

    // El SIN arma el CUIS con la hora: la vigencia es la emision + 1 ano.
    $recienEmitido = fn (string $codigo, bool $yaVigente = false) => (object) ['RespuestaCuis' => (object) array_filter([
        'codigo' => $codigo,
        'fechaVigencia' => now()->addYear()->format('Y-m-d\\TH:i:s.vP'),
        'transaccion' => ! $yaVigente,
        'mensajesList' => $yaVigente ? (object) ['codigo' => 980, 'descripcion' => 'EXISTE UN CUIS VIGENTE'] : null,
    ], fn ($v) => $v !== null)];

    // V2 emite uno ahora mismo; V3 lo encuentra vigente y tiene que esperar.
    $codigosV2 = Mockery::mock(ServicioCodigos::class);
    $codigosV2->shouldReceive('solicitarCuis')->once()->andReturn($recienEmitido('CUIS-V2'));
    $codigosV3 = Mockery::mock(ServicioCodigos::class);
    $codigosV3->shouldReceive('solicitarCuis')->twice()->andReturn($recienEmitido('CUIS-V2', yaVigente: true), $recienEmitido('CUIS-V3'));
    $operaciones = Mockery::mock(ServicioOperaciones::class);
    $operaciones->shouldReceive('cerrarOperacionesSistema')->once()->with(0, 0, 'CUIS-V2')->andReturn(cierreAceptado());

    $this->mock(FabricaServicios::class, function ($mock) use ($codigosV2, $codigosV3, $operaciones) {
        $mock->shouldReceive('codigos')->with(Mockery::any(), SiatClient::VERSION_V2)->andReturn($codigosV2);
        $mock->shouldReceive('codigos')->with(Mockery::any(), SiatClient::VERSION_V3)->andReturn($codigosV3);
        $mock->shouldReceive('operaciones')->andReturn($operaciones);
    });

    $this->post(route('admin.empresas.sucursales.store', $empresa), datosSucursal())
        ->assertSessionHas('estado', fn (string $m) => str_contains($m, 'V3: CUIS nuevo CUIS-V3.'));

    // 60 s de ventana antes del cierre, y la pausa corta antes del pedido.
    Sleep::assertSequence([Sleep::for(60)->seconds(), Sleep::for(2)->seconds()]);
});
