<?php

use App\Exceptions\CufdVencidoException;
use App\Exceptions\SiatException;
use App\Jobs\RenovarCufd;
use App\Models\Certificado;
use App\Models\Cufd;
use App\Models\Cuis;
use App\Models\Empresa;
use App\Models\EventoSignificativo;
use App\Models\Factura;
use App\Models\LeyendaFactura;
use App\Models\ProductoServicio;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Factura\EmisorFactura;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\GestorCodigos;
use App\Services\Siat\ServicioCodigos;
use App\Services\Siat\ServicioOperaciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

/**
 * Servicio de codigos simulado, entregado por la fabrica.
 */
function codigosDelSin(): ServicioCodigos
{
    $codigos = Mockery::mock(ServicioCodigos::class);
    test()->mock(FabricaServicios::class, fn ($mock) => $mock->shouldReceive('codigos')->andReturn($codigos));

    return $codigos;
}

function sinCuis(string $codigo = 'CUIS-NUEVO'): object
{
    return (object) ['RespuestaCuis' => (object) ['transaccion' => true, 'codigo' => $codigo, 'fechaVigencia' => now()->addYear()->toIso8601String()]];
}

function sinCufd(string $codigo = 'CUFD-NUEVO'): object
{
    return (object) ['RespuestaCufd' => (object) [
        'transaccion' => true, 'codigo' => $codigo, 'codigoControl' => 'CTRL123', 'direccion' => 'Calle 1',
        'fechaVigencia' => now()->addDay()->toIso8601String(),
    ]];
}

/**
 * Empresa en produccion, PV 0 (conocido por el SIN) y certificado; con CUIS
 * vigente opcional y SIN CUFD.
 */
function pv0SinCufd(bool $conCuis = true): PuntoVenta
{
    $empresa = Empresa::factory()->enProduccion()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $pv = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);
    Certificado::factory()->for($empresa)->firmable()->create();

    if ($conCuis) {
        Cuis::factory()->create(['punto_venta_id' => $pv->id, 'codigo' => 'CUIS-VIGENTE']);
    }

    return $pv;
}

/**
 * @return array<string, mixed>
 */
function ventaPv0(): array
{
    return [
        'sucursal' => 0,
        'punto_venta' => 0,
        'comprador' => ['tipo_documento' => 1, 'numero_documento' => '1234567', 'razon_social' => 'JUAN PEREZ'],
        'metodo_pago' => 1,
        'items' => [['codigo_producto_sin' => 83141, 'descripcion' => 'X', 'cantidad' => 1, 'unidad_medida' => 58, 'precio_unitario' => 10]],
    ];
}

function tokenDe(Empresa $empresa, string $perfil = 'administracion'): string
{
    return $empresa->emitirTokenApi('Test', $perfil)->plainTextToken;
}

// --- Fase 2: la emision consigue el CUFD sola ------------------------------------

test('sin CUFD vigente la emision lo pide al SIN y factura igual', function () {
    Queue::fake();
    $pv = pv0SinCufd();
    codigosDelSin()->shouldReceive('solicitarCufd')->once()->andReturn(sinCufd());

    $factura = app(EmisorFactura::class)->emitir($pv->sucursal->empresa, ventaPv0());

    expect($factura->cufd->codigo)->toBe('CUFD-NUEVO')
        ->and($factura->cufd->cuis->codigo)->toBe('CUIS-VIGENTE')
        ->and($factura->cuf)->toEndWith('CTRL123');
});

test('si tambien falta el CUIS, lo pide antes que el CUFD', function () {
    Queue::fake();
    $pv = pv0SinCufd(conCuis: false);
    $codigos = codigosDelSin();
    $codigos->shouldReceive('solicitarCuis')->once()->andReturn(sinCuis());
    $codigos->shouldReceive('solicitarCufd')->once()->with(Mockery::any(), 'CUIS-NUEVO')->andReturn(sinCufd());

    $factura = app(EmisorFactura::class)->emitir($pv->sucursal->empresa, ventaPv0());

    expect($factura->cufd->cuis->codigo)->toBe('CUIS-NUEVO');
});

test('con un CUFD vigente no se llama al SIN', function () {
    Queue::fake();
    $pv = pv0SinCufd();
    Cufd::factory()->create(['punto_venta_id' => $pv->id]);
    $this->mock(FabricaServicios::class, fn ($mock) => $mock->shouldNotReceive('codigos'));

    expect(app(EmisorFactura::class)->emitir($pv->sucursal->empresa, ventaPv0())->exists)->toBeTrue();
});

test('en contingencia no se intenta pedir CUFD: la caja no espera el timeout', function () {
    $pv = pv0SinCufd();
    EventoSignificativo::create([
        'empresa_id' => $pv->sucursal->empresa_id, 'punto_venta_id' => $pv->id, 'codigo_evento' => 1,
        'fecha_inicio' => now(), 'estado' => 'ABIERTO',
    ]);
    $this->mock(FabricaServicios::class, fn ($mock) => $mock->shouldNotReceive('codigos'));

    app(EmisorFactura::class)->emitir($pv->sucursal->empresa, ventaPv0());
})->throws(CufdVencidoException::class, 'el SIAT no esta respondiendo');

test('si el SIN falla, durante un minuto no se le vuelve a pedir', function () {
    $pv = pv0SinCufd();
    codigosDelSin()->shouldReceive('solicitarCufd')->once()->andThrow(new SiatException('timeout'));
    $emisor = app(EmisorFactura::class);

    expect(fn () => $emisor->emitir($pv->sucursal->empresa, ventaPv0()))->toThrow(CufdVencidoException::class, 'no entrego uno nuevo')
        ->and(fn () => $emisor->emitir($pv->sucursal->empresa, ventaPv0()))->toThrow(CufdVencidoException::class, 'no esta respondiendo');

    $this->travel(61)->seconds();
    codigosDelSin()->shouldReceive('solicitarCufd')->once()->andReturn(sinCufd());
    Queue::fake();

    expect(app(EmisorFactura::class)->emitir($pv->sucursal->empresa, ventaPv0())->exists)->toBeTrue();
});

// --- Fase 2: renovacion automatica ----------------------------------------------

test('la renovacion preventiva pide el CUIS si vencio', function () {
    $pv = pv0SinCufd(conCuis: false);
    $codigos = codigosDelSin();
    $codigos->shouldReceive('solicitarCuis')->once()->andReturn(sinCuis());
    $codigos->shouldReceive('solicitarCufd')->once()->andReturn(sinCufd());

    app()->call([new RenovarCufd($pv->id), 'handle']);

    expect($pv->cufdVigente()->codigo)->toBe('CUFD-NUEVO');
});

test('la renovacion preventiva ignora puntos de venta sin registro o empresas sin token', function (string $caso) {
    $pv = pv0SinCufd();
    $caso === 'sin registro'
        ? $pv->update(['codigo_punto_venta' => 7, 'registrado_en_siat' => null])
        : $pv->sucursal->empresa->update(['token_delegado' => null]);
    $this->mock(FabricaServicios::class, fn ($mock) => $mock->shouldNotReceive('codigos'));

    app()->call([new RenovarCufd($pv->id), 'handle']);
})->with(['sin registro', 'sin token']);

test('la revision diaria pide CUIS a los puntos de venta que lo perdieron, y un CUFD atado a el', function () {
    $pv = pv0SinCufd(conCuis: false);
    $codigos = codigosDelSin();
    $codigos->shouldReceive('solicitarCuis')->once()->andReturn(sinCuis());
    $codigos->shouldReceive('solicitarCufd')->once()->with(Mockery::any(), 'CUIS-NUEVO')->andReturn(sinCufd());

    $this->artisan('siat:revisar-codigos')
        ->expectsOutputToContain('CUIS renovados: 1')
        ->assertSuccessful();

    expect($pv->cuisVigente()->codigo)->toBe('CUIS-NUEVO')
        ->and($pv->cufdVigente()->cuis->codigo)->toBe('CUIS-NUEVO');
});

test('si el SIN entrega un CUIS nuevo, se renueva tambien el CUFD', function () {
    $pv = pv0SinCufd();
    Cufd::factory()->create(['punto_venta_id' => $pv->id, 'codigo' => 'CUFD-DEL-CUIS-VIEJO']);
    $codigos = codigosDelSin();
    $codigos->shouldReceive('solicitarCuis')->once()->andReturn(sinCuis('CUIS-OTRO'));
    $codigos->shouldReceive('solicitarCufd')->once()->with(Mockery::any(), 'CUIS-OTRO')->andReturn(sinCufd('CUFD-DEL-CUIS-NUEVO'));

    app(GestorCodigos::class)->renovarCuis($pv);

    expect($pv->cufdVigente()->codigo)->toBe('CUFD-DEL-CUIS-NUEVO')
        ->and($pv->cufdVigente()->cuis->codigo)->toBe('CUIS-OTRO');
});

test('si el SIN devuelve el mismo CUIS (980), el CUFD no se toca', function () {
    $pv = pv0SinCufd();
    Cufd::factory()->create(['punto_venta_id' => $pv->id, 'codigo' => 'CUFD-ACTUAL']);
    $codigos = codigosDelSin();
    $codigos->shouldReceive('solicitarCuis')->once()->andReturn(sinCuis('CUIS-VIGENTE'));
    $codigos->shouldNotReceive('solicitarCufd');

    app(GestorCodigos::class)->renovarCuis($pv);

    expect($pv->cufdVigente()->codigo)->toBe('CUFD-ACTUAL');
});

test('si el CUFD falla tras un CUIS nuevo, el CUIS queda guardado y se avisa', function () {
    $pv = pv0SinCufd();
    $codigos = codigosDelSin();
    $codigos->shouldReceive('solicitarCuis')->andReturn(sinCuis('CUIS-OTRO'));
    $codigos->shouldReceive('solicitarCufd')->andThrow(new SiatException('timeout'));

    expect(fn () => app(GestorCodigos::class)->renovarCuis($pv))
        ->toThrow(SiatException::class, 'CUIS nuevo obtenido, pero el CUFD atado a el no');

    expect($pv->cuisVigente()->codigo)->toBe('CUIS-OTRO');
});

test('el boton Solicitar CUIS del panel renueva tambien el CUFD si el CUIS cambio', function () {
    $this->actingAs(User::factory()->create());
    $pv = pv0SinCufd();
    $codigos = codigosDelSin();
    $codigos->shouldReceive('solicitarCuis')->once()->andReturn(sinCuis('CUIS-OTRO'));
    $codigos->shouldReceive('solicitarCufd')->once()->andReturn(sinCufd('CUFD-NUEVO'));

    $this->post(route('admin.codigos.cuis', $pv))->assertSessionHas('estado', fn (string $m) => str_contains($m, 'CUFD'));

    expect($pv->cufdVigente()->cuis->codigo)->toBe('CUIS-OTRO');
});

// --- Fase 2: endpoints de codigos -------------------------------------------------

test('GET codigos muestra vigencias y nunca los codigos', function () {
    $pv = pv0SinCufd();
    Cufd::factory()->create(['punto_venta_id' => $pv->id, 'codigo' => 'CUFD-SECRETO', 'codigo_control' => 'CTRL-SECRETO']);

    $respuesta = $this->withToken(tokenDe($pv->sucursal->empresa, 'caja'))
        ->getJson('/api/v1/puntos-venta/0/0/codigos')
        ->assertOk()
        ->assertJsonPath('cuis.vigente', true)
        ->assertJsonPath('cufd.vigente', true)
        ->assertJsonPath('puede_facturar', true);

    expect($respuesta->getContent())->not->toContain('SECRETO')->not->toContain('CUIS-VIGENTE');
});

test('GET codigos de un punto de venta ajeno da 404', function () {
    $pv = pv0SinCufd();

    $this->withToken(tokenDe(Empresa::factory()->create()))
        ->getJson('/api/v1/puntos-venta/0/0/codigos')
        ->assertNotFound();
});

test('POST cufd fuerza uno nuevo y limita las renovaciones', function () {
    $pv = pv0SinCufd();
    $token = tokenDe($pv->sucursal->empresa);
    codigosDelSin()->shouldReceive('solicitarCufd')->times(3)->andReturn(sinCufd('A'), sinCufd('B'), sinCufd('C'));

    foreach (range(1, 3) as $i) {
        $this->withToken($token)->postJson('/api/v1/puntos-venta/0/0/cufd')->assertCreated()->assertJsonPath('cufd.vigente', true);
    }

    $this->withToken($token)->postJson('/api/v1/puntos-venta/0/0/cufd')
        ->assertStatus(429)
        ->assertJsonPath('error', 'DEMASIADAS_RENOVACIONES');
});

test('POST cufd: caja sin permiso, punto de venta no registrado o SIN caido', function (string $caso, int $estado, string $error) {
    $pv = pv0SinCufd();
    $perfil = $caso === 'caja' ? 'caja' : 'administracion';

    if ($caso === 'no registrado') {
        PuntoVenta::factory()->sinRegistrarEnSiat()->for($pv->sucursal)->create(['codigo_punto_venta' => 5]);
    }

    if ($caso === 'SIN caido') {
        codigosDelSin()->shouldReceive('solicitarCufd')->andThrow(new SiatException('timeout'));
    }

    $ruta = $caso === 'no registrado' ? '/api/v1/puntos-venta/0/5/cufd' : '/api/v1/puntos-venta/0/0/cufd';

    $this->withToken(tokenDe($pv->sucursal->empresa, $perfil))
        ->postJson($ruta)
        ->assertStatus($estado)
        ->assertJsonPath('error', $error);
})->with([
    ['caja', 403, 'PERMISO_INSUFICIENTE'],
    ['no registrado', 409, 'PUNTO_VENTA_NO_REGISTRADO'],
    ['SIN caido', 503, 'SIAT_NO_DISPONIBLE'],
]);

test('estado dice que se puede facturar aunque todavia no haya CUFD', function () {
    $pv = pv0SinCufd();

    $this->withToken(tokenDe($pv->sucursal->empresa, 'caja'))
        ->getJson('/api/v1/estado')
        ->assertJsonPath('puede_facturar', true)
        ->assertJsonPath('puntos_venta_con_cufd', 0)
        ->assertJsonPath('puntos_venta_registrados_en_siat', 1);
});

// --- Fase 3: endpoints nuevos ------------------------------------------------------

test('GET sucursales lista las de la empresa con sus puntos de venta activos', function () {
    $pv = pv0SinCufd();
    PuntoVenta::factory()->for($pv->sucursal)->create(['codigo_punto_venta' => 3, 'activo' => false]);

    $this->withToken(tokenDe($pv->sucursal->empresa, 'consulta'))
        ->getJson('/api/v1/sucursales')
        ->assertOk()
        ->assertJsonCount(1, 'sucursales')
        ->assertJsonPath('sucursales.0.sucursal', 0)
        ->assertJsonCount(1, 'sucursales.0.puntos_venta')
        ->assertJsonPath('sucursales.0.puntos_venta.0.registrado_en_siat', true);
});

test('verificar NIT responde valido y codigo_excepcion, y recuerda la respuesta', function (bool $transaccion, int $excepcion) {
    $pv = pv0SinCufd();
    codigosDelSin()->shouldReceive('verificarNit')->once()->with('1023456028', 'CUIS-VIGENTE', 0)->andReturn((object) [
        'RespuestaVerificarNit' => (object) [
            'transaccion' => $transaccion,
            'mensajesList' => (object) ['codigo' => $transaccion ? 986 : 994, 'descripcion' => $transaccion ? 'NIT ACTIVO' : 'NIT INEXISTENTE'],
        ],
    ]);
    $token = tokenDe($pv->sucursal->empresa, 'caja');

    foreach ([1, 2] as $vez) {
        $this->withToken($token)->getJson('/api/v1/nit/1023456028/verificar')
            ->assertOk()
            ->assertJsonPath('valido', $transaccion)
            ->assertJsonPath('codigo_excepcion', $excepcion);
    }
})->with([
    'activo' => [true, 0],
    'inexistente' => [false, 1],
]);

test('verificar NIT: formato invalido, sin CUIS o SIN caido', function (string $caso, int $estado, string $error) {
    $pv = pv0SinCufd(conCuis: $caso !== 'sin CUIS');

    if ($caso === 'SIN caido') {
        codigosDelSin()->shouldReceive('verificarNit')->andThrow(new SiatException('timeout'));
    }

    $nit = $caso === 'formato' ? '12-AB' : '1023456028';

    $this->withToken(tokenDe($pv->sucursal->empresa, 'caja'))
        ->getJson("/api/v1/nit/{$nit}/verificar")
        ->assertStatus($estado)
        ->assertJsonPath('error', $error);
})->with([
    ['formato', 422, 'NIT_INVALIDO'],
    ['sin CUIS', 503, 'SIAT_NO_DISPONIBLE'],
    ['SIN caido', 503, 'SIAT_NO_DISPONIBLE'],
]);

test('el token de solo consulta no puede verificar NIT', function () {
    $pv = pv0SinCufd();

    $this->withToken(tokenDe($pv->sucursal->empresa, 'consulta'))
        ->getJson('/api/v1/nit/1023456028/verificar')
        ->assertForbidden();
});

test('catalogos de productos homologados y leyendas del NIT', function () {
    $empresa = Empresa::factory()->create();
    ProductoServicio::create(['empresa_id' => $empresa->id, 'codigo_actividad' => '620100', 'codigo_producto' => '83141', 'descripcion' => 'Martillo']);
    LeyendaFactura::create(['empresa_id' => $empresa->id, 'codigo_actividad' => '620100', 'descripcion_leyenda' => 'Ley 453']);
    ProductoServicio::create(['empresa_id' => Empresa::factory()->create()->id, 'codigo_actividad' => '1', 'codigo_producto' => '1', 'descripcion' => 'Ajeno']);
    $token = tokenDe($empresa, 'caja');

    $this->withToken($token)->getJson('/api/v1/catalogos/productos')
        ->assertOk()
        ->assertExactJson(['exito' => true, 'tipo' => 'productos', 'datos' => [['codigo' => '83141', 'actividad' => '620100', 'descripcion' => 'Martillo']]]);

    app('auth')->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/catalogos/leyendas')
        ->assertOk()
        ->assertJsonPath('datos.0.descripcion', 'Ley 453');
});

// --- Fase 4: la especificacion OpenAPI no se desincroniza ---------------------------

test('openapi.yaml documenta exactamente las rutas de la API v1 con el permiso que exigen', function () {
    $spec = Yaml::parseFile(base_path('docs/integracion/openapi.yaml'));

    // "METODO /api/v1/ruta" => permiso, segun la especificacion.
    $documentadas = collect($spec['paths'])
        ->flatMap(fn (array $operaciones, string $ruta) => collect($operaciones)->mapWithKeys(
            fn (array $operacion, string $metodo) => [strtoupper($metodo).' /api/v1'.$ruta => $operacion['x-permiso']],
        ))
        ->sortKeys()->all();

    // Lo mismo, segun las rutas registradas y su middleware siat.permiso.
    $reales = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($ruta) => str_starts_with($ruta->uri(), 'api/v1/'))
        ->mapWithKeys(fn ($ruta) => [
            collect($ruta->methods())->reject(fn ($m) => $m === 'HEAD')->first().' /'.$ruta->uri() => Str::after(
                collect($ruta->gatherMiddleware())->first(fn ($m) => str_starts_with($m, 'siat.permiso:')),
                'siat.permiso:',
            ),
        ])
        ->sortKeys()->all();

    expect($documentadas)->toBe($reales)
        ->and(array_diff(array_unique($reales), array_keys(config('siat.api.permisos'))))->toBe([]);
});

// --- Forzar un CUIS nuevo (dialogo del panel) --------------------------------------

/**
 * Fabrica simulada con servicio de codigos y de operaciones.
 *
 * @return array{0: ServicioCodigos, 1: ServicioOperaciones}
 */
function codigosYOperaciones(): array
{
    $codigos = Mockery::mock(ServicioCodigos::class);
    $operaciones = Mockery::mock(ServicioOperaciones::class);
    test()->mock(FabricaServicios::class, function ($mock) use ($codigos, $operaciones) {
        $mock->shouldReceive('codigos')->andReturn($codigos);
        $mock->shouldReceive('operaciones')->andReturn($operaciones);
    });

    return [$codigos, $operaciones];
}

function cierre(bool $aceptado): object
{
    return (object) ['RespuestaCierreSistemas' => (object) array_filter([
        'transaccion' => $aceptado,
        'mensajesList' => $aceptado ? null : (object) ['codigo' => 999, 'descripcion' => 'NO SE PUDO CERRAR'],
    ], fn ($v) => $v !== null)];
}

test('forzar CUIS cierra operaciones con el CUIS que dice el SIN y trae uno nuevo con su CUFD', function () {
    $pv = pv0SinCufd();
    [$codigos, $operaciones] = codigosYOperaciones();
    $codigos->shouldReceive('solicitarCuis')->twice()->andReturn(sinCuis('CUIS-VIGENTE'), sinCuis('CUIS-NUEVO'));
    $operaciones->shouldReceive('cerrarOperacionesSistema')->once()->with(0, 0, 'CUIS-VIGENTE')->andReturn(cierre(true));
    $codigos->shouldReceive('solicitarCufd')->once()->with(Mockery::any(), 'CUIS-NUEVO')->andReturn(sinCufd('CUFD-NUEVO'));

    $nuevo = app(GestorCodigos::class)->forzarCuisNuevo($pv);

    expect($nuevo->codigo)->toBe('CUIS-NUEVO')
        ->and($pv->cufdVigente()->cuis->codigo)->toBe('CUIS-NUEVO');
});

test('forzar CUIS: si el SIN no cierra, no se pide nada mas', function () {
    $pv = pv0SinCufd();
    [$codigos, $operaciones] = codigosYOperaciones();
    $codigos->shouldReceive('solicitarCuis')->once()->andReturn(sinCuis('CUIS-VIGENTE'));
    $operaciones->shouldReceive('cerrarOperacionesSistema')->andReturn(cierre(false));
    $codigos->shouldNotReceive('solicitarCufd');

    expect(fn () => app(GestorCodigos::class)->forzarCuisNuevo($pv))
        ->toThrow(SiatException::class, 'El SIN no cerro las operaciones, el CUIS sigue siendo CUIS-VIGENTE: [999] NO SE PUDO CERRAR');
});

test('forzar CUIS: si tras cerrar el SIN devuelve el mismo, se avisa', function () {
    $pv = pv0SinCufd();
    [$codigos, $operaciones] = codigosYOperaciones();
    $codigos->shouldReceive('solicitarCuis')->twice()->andReturn(sinCuis('CUIS-VIGENTE'));
    $operaciones->shouldReceive('cerrarOperacionesSistema')->andReturn(cierre(true));
    $codigos->shouldNotReceive('solicitarCufd');

    expect(fn () => app(GestorCodigos::class)->forzarCuisNuevo($pv))
        ->toThrow(SiatException::class, 'devolvio el mismo CUIS');
});

test('el dialogo ofrece revisar o forzar, y avisa de las facturas pendientes', function () {
    $this->actingAs(User::factory()->create());
    $pv = pv0SinCufd();
    Factura::factory()->create(['punto_venta_id' => $pv->id, 'empresa_id' => $pv->sucursal->empresa_id, 'estado' => 'PENDIENTE']);

    $this->get(route('admin.puntos-venta.codigos', $pv))
        ->assertOk()
        ->assertSee('CUIS actual: <code>CUIS-VIGENTE</code>', false)
        ->assertSee('Revisar CUIS')
        ->assertSee('Forzar CUIS nuevo')
        ->assertSee('Hay 1 factura(s) PENDIENTE(S)')
        ->assertSee(route('admin.codigos.cuis-nuevo', $pv));
});

test('sin CUIS vigente el dialogo solo ofrece pedirlo', function () {
    $this->actingAs(User::factory()->create());
    $pv = pv0SinCufd(conCuis: false);

    $this->get(route('admin.puntos-venta.codigos', $pv))
        ->assertSee('Pedir CUIS')
        ->assertDontSee('Forzar CUIS nuevo');
});

test('un error al forzar vuelve a la misma pantalla y se muestra en rojo', function () {
    $this->actingAs(User::factory()->create());
    $pv = pv0SinCufd();
    [$codigos, $operaciones] = codigosYOperaciones();
    $codigos->shouldReceive('solicitarCuis')->andReturn(sinCuis('CUIS-VIGENTE'));
    $operaciones->shouldReceive('cerrarOperacionesSistema')->andReturn(cierre(false));

    $this->from(route('admin.puntos-venta.codigos', $pv))
        ->post(route('admin.codigos.cuis-nuevo', $pv))
        ->assertRedirect(route('admin.puntos-venta.codigos', $pv))
        ->assertSessionHas('error', fn (string $m) => str_contains($m, 'NO SE PUDO CERRAR'));
});

// --- Documentacion publica (/docs/api) ------------------------------------------------

test('la documentacion publica se abre sin sesion y carga Redoc con la especificacion', function () {
    $this->get('/docs/api')
        ->assertOk()
        ->assertSee('<redoc spec-url="'.route('docs.api.especificacion').'"', false)
        ->assertSee(asset('vendor/redoc/redoc.standalone.js'), false)
        ->assertSee('token de su empresa', false);

    expect(file_exists(public_path('vendor/redoc/redoc.standalone.js')))->toBeTrue();
});

test('la especificacion publica apunta al servidor real de la instalacion', function () {
    config(['app.url' => 'https://facturas.proveedor.bo']);

    $respuesta = $this->get('/docs/api/openapi.yaml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/yaml; charset=utf-8');

    $spec = Yaml::parse($respuesta->getContent());

    expect($spec['servers'])->toBe([['url' => 'https://facturas.proveedor.bo/api/v1']])
        ->and(array_keys($spec['paths']))->toContain('/facturas', '/estado', '/nit/{nit}/verificar')
        ->and($spec['info']['description'])->toContain('# Primeros pasos');
});

test('la documentacion publica no expone nada privado', function () {
    config(['siat.proveedor.nit' => '7633685015', 'siat.proveedor.codigo_sistema' => '22848EC6F66C9401C16F7']);
    $empresa = Empresa::factory()->create();
    $token = $empresa->emitirTokenApi('T', 'caja')->plainTextToken;

    $contenido = $this->get('/docs/api')->getContent().$this->get('/docs/api/openapi.yaml')->getContent();

    expect($contenido)
        ->not->toContain('7633685015')
        ->not->toContain('22848EC6F66C9401C16F7')
        ->not->toContain(explode('|', $token)[1]);
});

// --- Coleccion de Postman / Thunder Client ------------------------------------------------

test('la coleccion de Postman cubre todas las rutas de la API', function () {
    $coleccion = json_decode(file_get_contents(base_path('docs/integracion/postman/coleccion.json')), true);

    $enColeccion = collect($coleccion['item'])->flatMap(fn ($carpeta) => $carpeta['item'])
        ->map(fn ($r) => $r['request']['method'].' '.preg_replace(
            ['#\{\{base_url\}\}#', '#\?.*$#', '#/[0-9]{5,}/#', '#\{\{cuf\}\}#', '#\{\{sucursal\}\}/\{\{punto_venta\}\}#', '#/catalogos/\w+#'],
            ['/api/v1', '', '/{nit}/', '{cuf}', '{sucursal}/{puntoVenta}', '/catalogos/{tipo}'],
            $r['request']['url']['raw'],
        ))
        ->unique()->sort()->values()->all();

    $reales = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($ruta) => str_starts_with($ruta->uri(), 'api/v1/'))
        ->map(fn ($ruta) => collect($ruta->methods())->reject(fn ($m) => $m === 'HEAD')->first().' /'.$ruta->uri())
        ->reject(fn ($r) => $r === 'POST /api/v1/puntos-venta') // alta local: no es parte del flujo de prueba
        ->sort()->values()->all();

    expect($enColeccion)->toBe($reales);
});

test('cada peticion de la coleccion funciona de punta a punta, en orden', function () {
    Queue::fake();
    $pv = pv0SinCufd();
    Cufd::factory()->create(['punto_venta_id' => $pv->id]);
    $empresa = $pv->sucursal->empresa;
    ProductoServicio::create(['empresa_id' => $empresa->id, 'codigo_actividad' => '620100', 'codigo_producto' => '83141', 'descripcion' => 'Martillo']);

    // El SIN simulado solo para las dos llamadas que salen al SIAT.
    $codigos = codigosDelSin();
    $codigos->shouldReceive('verificarNit')->andReturn((object) ['RespuestaVerificarNit' => (object) ['transaccion' => true]]);
    $codigos->shouldReceive('solicitarCufd')->andReturn(sinCufd('CUFD-EMERGENCIA'));

    $variables = [
        'base_url' => '/api/v1',
        'token' => $empresa->emitirTokenApi('Postman', 'administracion')->plainTextToken,
        'sucursal' => '0',
        'punto_venta' => '0',
        'venta_id' => 'VENTA-0001',
        'cuf' => '',
    ];

    // Por referencia: el CUF se guarda a mitad de camino, como en Postman.
    $sustituir = function (string $texto) use (&$variables): string {
        return preg_replace_callback('/\{\{(\w+)\}\}/', fn ($m) => $variables[$m[1]], $texto);
    };
    $coleccion = json_decode(file_get_contents(base_path('docs/integracion/postman/coleccion.json')), true);

    foreach (collect($coleccion['item'])->flatMap(fn ($c) => $c['item']) as $peticion) {
        $r = $peticion['request'];
        $cabeceras = collect($r['header'])->mapWithKeys(fn ($h) => [$h['key'] => $sustituir($h['value'])])->all();
        $cabeceras['Authorization'] = 'Bearer '.$variables['token'];
        $cuerpo = isset($r['body']) ? json_decode($sustituir($r['body']['raw']), true) : [];

        app('auth')->forgetGuards();
        $respuesta = $this->withHeaders($cabeceras)->json($r['method'], $sustituir($r['url']['raw']), $cuerpo);

        expect($respuesta->status())->toBeLessThan(400, "{$peticion['name']} respondio {$respuesta->status()}: ".mb_substr($respuesta->getContent(), 0, 200));

        // Lo mismo que hace el script de la coleccion: guardar el CUF. Y lo
        // que hace el worker en segundos: enviarla y que el SIN la valide
        // (una PENDIENTE no se puede anular, como avisa la guia).
        if ($peticion['name'] === 'Emitir factura') {
            $variables['cuf'] = $respuesta->json('factura.cuf');
            Factura::where('cuf', $variables['cuf'])->update(['estado' => Factura::ESTADO_VALIDADA]);
        }
    }

    expect($variables['cuf'])->not->toBe('');
});

test('la coleccion y el entorno se descargan desde /docs/api con la URL real', function () {
    config(['app.url' => 'https://facturas.proveedor.bo']);

    $this->get('/docs/api')
        ->assertSee(route('docs.api.postman.coleccion'), false)
        ->assertSee(route('docs.api.postman.entorno'), false);

    $this->get(route('docs.api.postman.coleccion'))->assertOk()->assertJsonPath('info.schema', 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json');

    $entorno = $this->get(route('docs.api.postman.entorno'))->assertOk()->json();
    expect(collect($entorno['values'])->firstWhere('key', 'base_url')['value'])->toBe('https://facturas.proveedor.bo/api/v1');
});
