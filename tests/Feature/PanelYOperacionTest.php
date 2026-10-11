<?php

use App\Jobs\EnviarFacturaAlSiat;
use App\Jobs\VerificarEstadoFactura;
use App\Models\Certificado;
use App\Models\Cufd;
use App\Models\Cuis;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\FacturaItem;
use App\Models\LogApi;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Factura\MontoEnLetras;
use App\Services\Panel\PuntosVentaSiat;
use App\Services\Panel\RequisitosEtapa;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\ServicioOperaciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Token delegado con forma de JWT y el vencimiento indicado.
 */
function jwtQueVence(DateTimeInterface $vence): string
{
    $parte = fn (array $datos) => rtrim(strtr(base64_encode(json_encode($datos)), '+/', '-_'), '=');

    return $parte(['alg' => 'HS512']).'.'.$parte(['sub' => 'NIT', 'exp' => $vence->getTimestamp()]).'.firma';
}

// --- B: monto en letras y representacion grafica ------------------------------------

test('el monto se escribe en letras como en las facturas bolivianas', function (float $monto, string $texto) {
    expect(app(MontoEnLetras::class)->bolivianos($monto))->toBe($texto);
})->with([
    [0, 'CERO 00/100 BOLIVIANOS'],
    [1, 'UNO 00/100 BOLIVIANOS'],
    [15.5, 'QUINCE 50/100 BOLIVIANOS'],
    [21, 'VEINTIUNO 00/100 BOLIVIANOS'],
    [100, 'CIEN 00/100 BOLIVIANOS'],
    [101, 'CIENTO UNO 00/100 BOLIVIANOS'],
    [1000, 'UN MIL 00/100 BOLIVIANOS'],
    [1234.5, 'UN MIL DOSCIENTOS TREINTA Y CUATRO 50/100 BOLIVIANOS'],
    [21000, 'VEINTIUN MIL 00/100 BOLIVIANOS'],
    [31001, 'TREINTA Y UN MIL UNO 00/100 BOLIVIANOS'],
    [1000000, 'UN MILLON 00/100 BOLIVIANOS'],
    [2500000.99, 'DOS MILLONES QUINIENTOS MIL 99/100 BOLIVIANOS'],
    [0.1 + 0.2, 'CERO 30/100 BOLIVIANOS'],
]);

test('la representacion grafica lleva todo lo que espera el SIN', function () {
    $empresa = Empresa::factory()->create(['razon_social' => 'FERRETERIA SOL', 'nit' => '1023456028']);
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0, 'direccion' => 'Av. Arce 1', 'municipio' => 'La Paz']);
    $pv = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);
    $factura = Factura::factory()->for($empresa)->create([
        'punto_venta_id' => $pv->id, 'monto_total' => 90, 'gift_card' => 0, 'monto_total_sujeto_iva' => 90, 'leyenda' => 'Ley N° 453',
    ]);
    FacturaItem::factory()->for($factura)->create(['unidad_medida' => 58]);

    $html = view('factura.pdf', [
        'factura' => $factura->fresh(['empresa', 'puntoVenta.sucursal', 'items']),
        'qr' => 'data:image/svg+xml;base64,',
        'urlVerificacion' => 'https://pilotosiat.impuestos.gob.bo/consulta/QR',
        'montoLiteral' => app(MontoEnLetras::class)->bolivianos(90),
        'unidades' => ['58' => 'UNIDAD (SERVICIOS)'],
    ])->render();

    expect($html)
        ->toContain('(Con Derecho a Crédito Fiscal)')
        ->toContain('CASA MATRIZ')
        ->toContain('Av. Arce 1')
        ->toContain('NIT:</strong> 1023456028')
        ->toContain('UNIDAD (SERVICIOS)')
        ->toContain('MONTO A PAGAR Bs')
        ->toContain('IMPORTE BASE CRÉDITO FISCAL Bs')
        ->toContain('Son: NOVENTA 00/100 BOLIVIANOS')
        ->toContain('Ley N° 453')
        ->toContain('Representación Gráfica de un Documento Fiscal Digital emitido en una modalidad de facturación en línea')
        ->toContain('ESTA FACTURA CONTRIBUYE AL DESARROLLO DEL PAÍS');
});

// --- E: facturas en el panel -------------------------------------------------------

test('el panel lista facturas y filtra por estado, empresa y busqueda', function () {
    $this->actingAs(User::factory()->create());
    $a = Factura::factory()->create(['estado' => Factura::ESTADO_VALIDADA, 'cuf' => 'CUF-AAA', 'referencia_externa' => 'VTA-1', 'comprador_razon_social' => 'COMPRADOR UNO']);
    $b = Factura::factory()->create(['estado' => Factura::ESTADO_OBSERVADA, 'cuf' => 'CUF-BBB', 'comprador_razon_social' => 'COMPRADOR DOS', 'motivo_observacion' => '[1001] HASH INVALIDO']);

    $this->get(route('admin.facturas.index'))->assertOk()->assertSee('COMPRADOR UNO')->assertSee('COMPRADOR DOS')->assertSee('[1001] HASH INVALIDO');
    $this->get(route('admin.facturas.index', ['estado' => 'OBSERVADA']))->assertSee('COMPRADOR DOS')->assertDontSee('COMPRADOR UNO');
    $this->get(route('admin.facturas.index', ['q' => 'VTA-1']))->assertSee('COMPRADOR UNO')->assertDontSee('COMPRADOR DOS');
    $this->get(route('admin.facturas.index', ['q' => 'CUF-BBB']))->assertSee('COMPRADOR DOS')->assertDontSee('COMPRADOR UNO');
    $this->get(route('admin.facturas.index', ['empresa' => $a->empresa_id]))->assertSee('COMPRADOR UNO')->assertDontSee('COMPRADOR DOS');
});

test('el detalle muestra el motivo de la observacion y descarga el XML', function () {
    $this->actingAs(User::factory()->create());
    $factura = Factura::factory()->create([
        'estado' => Factura::ESTADO_OBSERVADA, 'motivo_observacion' => '[1001] HASH INVALIDO', 'xml_firmado' => '<factura/>',
    ]);

    $this->get(route('admin.facturas.show', $factura))->assertOk()->assertSee('El SIN observo esta factura')->assertSee('[1001] HASH INVALIDO');
    $this->get(route('admin.facturas.xml', $factura))->assertOk()->assertHeader('Content-Type', 'application/xml');
});

test('verificar y reenviar solo actuan en el estado que corresponde', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    $recibida = Factura::factory()->create(['estado' => Factura::ESTADO_RECIBIDA]);
    $pendiente = Factura::factory()->create(['estado' => Factura::ESTADO_PENDIENTE]);
    $validada = Factura::factory()->validada()->create();

    $this->post(route('admin.facturas.verificar', $recibida))->assertSessionHas('estado');
    $this->post(route('admin.facturas.reenviar', $pendiente))->assertSessionHas('estado');
    $this->post(route('admin.facturas.verificar', $validada))->assertSessionHas('error');
    $this->post(route('admin.facturas.reenviar', $validada))->assertSessionHas('error');

    Queue::assertPushed(VerificarEstadoFactura::class, 1);
    Queue::assertPushed(EnviarFacturaAlSiat::class, 1);
});

test('las facturas del panel exigen sesion', function () {
    $this->get(route('admin.facturas.index'))->assertRedirect(route('login'));
});

test('el historial de codigos pagina los CUFD con la vista propia del panel', function () {
    $this->actingAs(User::factory()->create());
    $puntoVenta = PuntoVenta::factory()->create();
    Cufd::factory()->count(30)->for($puntoVenta)->create();

    $this->get(route('admin.puntos-venta.codigos', $puntoVenta))
        ->assertOk()
        ->assertSee('class="paginacion"', false)
        ->assertSee('Mostrando 1–25 de 30')
        ->assertSee('Siguiente')
        ->assertDontSee('Showing');

    $this->get(route('admin.puntos-venta.codigos', [$puntoVenta, 'page' => 2]))
        ->assertOk()
        ->assertSee('Mostrando 26–30 de 30');
});

// --- F: filtros de la API -----------------------------------------------------------

test('GET /facturas filtra por estado, referencia y punto de venta', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $pv0 = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);
    $pv1 = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 1]);
    $a = Factura::factory()->for($empresa)->create(['punto_venta_id' => $pv0->id, 'estado' => 'VALIDADA', 'referencia_externa' => 'VTA-1']);
    $b = Factura::factory()->for($empresa)->create(['punto_venta_id' => $pv1->id, 'estado' => 'OBSERVADA', 'referencia_externa' => 'VTA-2']);
    $token = $empresa->emitirTokenApi('T', 'caja')->plainTextToken;

    $cufs = function (array $filtros) use ($token) {
        app('auth')->forgetGuards();

        return collect($this->withToken($token)->getJson('/api/v1/facturas?'.http_build_query($filtros))->assertOk()->json('facturas'))->pluck('cuf')->all();
    };

    expect($cufs(['estado' => 'OBSERVADA']))->toBe([$b->cuf])
        ->and($cufs(['referencia_externa' => 'VTA-1']))->toBe([$a->cuf])
        ->and($cufs(['sucursal' => 0, 'punto_venta' => 1]))->toBe([$b->cuf]);

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/facturas?estado=INVENTADO')->assertUnprocessable();
});

// --- G: vencimiento del token delegado -------------------------------------------------

test('se lee el vencimiento del token delegado del propio JWT', function () {
    $vence = now()->addDays(10)->startOfSecond();

    expect(Empresa::factory()->make(['token_delegado' => jwtQueVence($vence)])->vencimientoTokenDelegado()->equalTo($vence))->toBeTrue()
        ->and(Empresa::factory()->make(['token_delegado' => 'no-es-un-jwt'])->vencimientoTokenDelegado())->toBeNull()
        ->and(Empresa::factory()->make(['token_delegado' => null])->vencimientoTokenDelegado())->toBeNull();
});

test('la ficha muestra el vencimiento y el checklist no da por bueno un token vencido', function () {
    $this->actingAs(User::factory()->create());
    $porVencer = Empresa::factory()->create(['token_delegado' => jwtQueVence(now()->addDays(5))]);
    $vencido = Empresa::factory()->create(['token_delegado' => jwtQueVence(now()->subDay())]);

    $this->get(route('admin.empresas.show', $porVencer))->assertSee('cargado, vence el '.now()->addDays(5)->format('d/m/Y'));
    $this->get(route('admin.empresas.show', $vencido))->assertSee('vencio el');

    $requisito = collect(app(RequisitosEtapa::class)->para($vencido)['requisitos'])
        ->firstWhere('titulo', 'Token delegado cargado y vigente');

    expect($requisito['cumplido'])->toBeFalse();
});

test('el aviso diario alerta certificados y tokens delegados y lo deja en el log', function () {
    Log::spy();
    Empresa::factory()->create(['nombre_comercial' => 'Tienda Token', 'token_delegado' => jwtQueVence(now()->addDays(3))]);
    Empresa::factory()->create(['nombre_comercial' => 'Tienda Lejos', 'token_delegado' => jwtQueVence(now()->addYear())]);
    Certificado::factory()->for(Empresa::factory()->create(['nombre_comercial' => 'Tienda Cert']))->create(['vence_el' => now()->addDays(7)]);

    $this->artisan('siat:avisar-certificados')
        ->expectsOutputToContain('Token delegado de Tienda Token vence')
        ->expectsOutputToContain('Certificado de Tienda Cert vence')
        ->doesntExpectOutputToContain('Tienda Lejos')
        ->assertSuccessful();

    Log::shouldHaveReceived('warning')->twice();
});

// --- H: limite por token -------------------------------------------------------------

test('un token que supera su limite no frena a los demas tokens de la empresa', function () {
    config(['siat.api.rate_limit_token' => 2, 'siat.api.rate_limit' => 100]);
    $empresa = Empresa::factory()->create();
    $caja1 = $empresa->emitirTokenApi('Caja 1', 'caja')->plainTextToken;
    $caja2 = $empresa->emitirTokenApi('Caja 2', 'caja')->plainTextToken;

    $estado = function (string $token) {
        app('auth')->forgetGuards();

        return $this->withToken($token)->getJson('/api/v1/estado')->status();
    };

    expect([$estado($caja1), $estado($caja1), $estado($caja1), $estado($caja2)])->toBe([200, 200, 429, 200]);
});

// --- I: llamadas a la API en el monitor -------------------------------------------------

test('el monitor muestra las llamadas a la API y filtra las fallidas', function () {
    $this->actingAs(User::factory()->create());
    $empresa = Empresa::factory()->create();
    $token = $empresa->emitirTokenApi('Caja centro', 'caja')->accessToken;
    LogApi::create(['empresa_id' => $empresa->id, 'token_id' => $token->id, 'metodo' => 'POST', 'ruta' => '/api/v1/facturas', 'estado_http' => 201, 'ip' => '200.87.1.10', 'created_at' => now()]);
    LogApi::create(['metodo' => 'GET', 'ruta' => '/api/v1/estado', 'estado_http' => 401, 'ip' => '45.1.1.1', 'created_at' => now()]);

    $this->get(route('admin.monitor'))->assertOk()->assertSee('Caja centro')->assertSee('200.87.1.10')->assertSee('45.1.1.1');
    $this->get(route('admin.monitor', ['errores' => 1]))->assertSee('45.1.1.1')->assertDontSee('200.87.1.10');
});

// --- J: proxies de confianza ---------------------------------------------------------

test('detras de un proxy configurado, la restriccion de IP ve la IP real del cliente', function () {
    config(['siat.proxies_confiables' => '10.0.0.1']);
    (new AppServiceProvider(app()))->boot();

    $token = Empresa::factory()->create()->emitirTokenApi('Caja', 'caja', null, ['200.87.1.10'])->plainTextToken;

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeader('X-Forwarded-For', '200.87.1.10')
        ->withToken($token)
        ->getJson('/api/v1/estado')
        ->assertOk();

    TrustProxies::flushState();
});

test('sin proxies configurados no se le cree a X-Forwarded-For', function () {
    TrustProxies::flushState();
    $token = Empresa::factory()->create()->emitirTokenApi('Caja', 'caja', null, ['200.87.1.10'])->plainTextToken;

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeader('X-Forwarded-For', '200.87.1.10')
        ->withToken($token)
        ->getJson('/api/v1/estado')
        ->assertForbidden();
});

// --- Lista de puntos de venta del SIN en la ficha ---------------------------------------

test('un error del SIN al listar puntos de venta no queda cacheado; una lista buena si', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $pv = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);
    Cuis::factory()->create(['punto_venta_id' => $pv->id, 'codigo' => 'CUIS-PV0']);

    $operaciones = Mockery::mock(ServicioOperaciones::class);
    $operaciones->shouldReceive('consultarPuntosVenta')->with(0, 'CUIS-PV0')->times(3)->andReturn(
        (object) ['RespuestaConsultaPuntoVenta' => (object) ['transaccion' => false, 'mensajesList' => (object) ['codigo' => 913, 'descripcion' => 'CUIS INVALIDO']]],
        (object) ['RespuestaConsultaPuntoVenta' => (object) ['transaccion' => true, 'listaPuntosVentas' => (object) ['codigoPuntoVenta' => 1, 'nombrePuntoVenta' => 'CAJA', 'tipoPuntoVenta' => 'CAJEROS']]],
        (object) ['RespuestaConsultaPuntoVenta' => (object) ['transaccion' => true]],
    );
    $this->mock(FabricaServicios::class, fn ($mock) => $mock->shouldReceive('operaciones')->andReturn($operaciones));

    $siat = app(PuntosVentaSiat::class);

    // 1. El SIN rechaza: se muestra el error, pero no se guarda.
    expect($siat->deSucursal($sucursal->fresh())['error'])->toContain('913');

    // 2. Se vuelve a preguntar y ahora responde bien: esa respuesta si se guarda...
    expect($siat->deSucursal($sucursal->fresh())['error'])->toBeNull();

    // 3. ...asi que la siguiente lectura sale del cache, sin llamar al SIN.
    expect($siat->deSucursal($sucursal->fresh())['error'])->toBeNull();

    // Olvidar el cache fuerza una consulta nueva: la tercera y ultima llamada.
    $siat->olvidar($sucursal);
    expect($siat->deSucursal($sucursal->fresh())['consultado'])->toBeTrue();
});

// --- Modal de confirmacion global --------------------------------------------------------

test('el panel tiene un modal de confirmacion global y las acciones peligrosas lo usan', function () {
    $this->actingAs(User::factory()->create());
    $empresa = Empresa::factory()->create();
    $empresa->emitirTokenApi('T', 'caja');

    $this->get(route('admin.empresas.show', $empresa))
        ->assertOk()
        ->assertSee('id="modal-confirmar"', false)
        ->assertSee('data-titulo="Eliminar empresa"', false);

    $this->get(route('admin.empresas.api', $empresa))
        ->assertSee('data-titulo="Revocar token"', false)
        ->assertSee('data-titulo="Regenerar token"', false);
});

test('ninguna vista usa el confirm() del navegador', function () {
    $conConfirm = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($archivo) => str_contains($archivo->getContents(), 'return confirm('))
        ->map(fn ($archivo) => $archivo->getRelativePathname())
        ->values()
        ->all();

    expect($conConfirm)->toBe([]);
});
