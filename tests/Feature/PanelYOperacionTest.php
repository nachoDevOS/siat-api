<?php

use App\Jobs\AnularFacturaEnSiat;
use App\Jobs\EnviarFacturaAlSiat;
use App\Jobs\NotificarWebhook;
use App\Jobs\RenovarCufd;
use App\Jobs\VerificarEstadoFactura;
use App\Models\Cafc;
use App\Models\Catalogo;
use App\Models\Certificado;
use App\Models\Cufd;
use App\Models\Cuis;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\FacturaAnulada;
use App\Models\FacturaItem;
use App\Models\LogApi;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Models\Trabajo;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Factura\MontoEnLetras;
use App\Services\Panel\PuntosVentaSiat;
use App\Services\Panel\RequisitosEtapa;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\GestorCodigos;
use App\Services\Siat\ServicioFacturacion;
use App\Services\Siat\ServicioOperaciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

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
        'punto_venta_id' => $pv->id, 'subtotal' => 79.65, 'total_precio_neto' => 79.65, 'iva' => 10.35,
        'monto_total' => 90, 'gift_card' => 0, 'leyenda' => 'Ley N° 453',
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
        ->toContain('1023456028')
        ->toContain('Unidad (servicios)')
        // Totales del formato grafico V3 (IVA Transparente).
        ->toContain('TOTAL PRECIO NETO Bs')
        ->toContain('<td class="total-valor">79.65</td>')
        ->toContain('IVA Bs')
        ->toContain('<td class="total-valor">10.35</td>')
        ->toContain('MONTO A PAGAR Bs')
        ->toContain('<td class="total-valor fuerte">90.00</td>')
        ->not->toContain('IMPORTE BASE CRÉDITO FISCAL')
        ->toContain('Son: Noventa 00/100 Bolivianos')
        ->toContain('Ley N° 453')
        ->toContain('Representación Gráfica de un Documento Fiscal Digital emitido en una modalidad de facturación en línea')
        ->toContain('ESTA FACTURA CONTRIBUYE AL DESARROLLO DEL PAÍS')
        ->toContain('SIN VALOR LEGAL');
});

test('solo la factura de piloto lleva la marca SIN VALOR LEGAL, en carta y en ticket', function () {
    $datos = fn (Factura $factura) => [
        'factura' => $factura->fresh(['empresa', 'puntoVenta.sucursal', 'items']),
        'qr' => 'data:image/svg+xml;base64,',
        'urlVerificacion' => 'https://siat.impuestos.gob.bo/consulta/QR',
        'montoLiteral' => 'DIEZ 00/100 BOLIVIANOS',
        'unidades' => [],
    ];

    $piloto = Factura::factory()->for(Empresa::factory()->create())->create();
    $produccion = Factura::factory()->for(Empresa::factory()->enProduccion()->create())->create();

    expect(view('factura.pdf', $datos($piloto))->render())->toContain('SIN VALOR LEGAL')
        ->and(view('factura.pdf', $datos($produccion))->render())->not->toContain('SIN VALOR LEGAL')
        ->and(view('factura.rollo', $datos($piloto))->render())->toContain('SIN VALOR LEGAL')
        ->and(view('factura.rollo', $datos($produccion))->render())->not->toContain('SIN VALOR LEGAL');
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
    Cufd::factory()->count(30)->for($puntoVenta)->create(['version' => 'v3']);

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

test('el historial de CUFD muestra cuando se solicito cada uno, con segundos', function () {
    $this->actingAs(User::factory()->create());
    $puntoVenta = PuntoVenta::factory()->create();
    $cufd = Cufd::factory()->for($puntoVenta)->create(['version' => 'v3']);

    $this->get(route('admin.puntos-venta.codigos', $puntoVenta))
        ->assertOk()
        ->assertSeeInOrder(['CUIS</th>', 'Solicitado</th>'], false)
        ->assertSee($cufd->created_at->format('d/m/Y H:i:s'));
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

test('el vencimiento del token delegado se muestra en hora de Bolivia, no en UTC', function () {
    // 22:30 en La Paz ya es el dia siguiente en UTC.
    $this->travelTo(Carbon::parse('2026-10-11 22:30:00', 'America/La_Paz'));
    $vence = Carbon::parse('2026-10-20 22:30:00', 'America/La_Paz');

    $empresa = Empresa::factory()->make(['token_delegado' => jwtQueVence($vence)]);

    expect($empresa->vencimientoTokenDelegado()->format('d/m/Y H:i'))->toBe('20/10/2026 22:30');
});

test('la vigencia que manda el SIN se guarda en hora de Bolivia aunque venga en UTC', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $pv = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);

    $cuis = app(GestorCodigos::class)->guardarCuis($pv, (object) ['RespuestaCuis' => (object) [
        'codigo' => 'CUIS-UTC', 'transaccion' => true, 'fechaVigencia' => '2027-10-07T14:00:00.000Z',
    ]]);

    // 14:00 UTC = 10:00 en La Paz.
    expect($cuis->fresh()->fecha_vigencia->format('Y-m-d H:i'))->toBe('2027-10-07 10:00');
});

test('el vencimiento de un certificado se toma en hora de Bolivia', function () {
    // El mismo instante del X509, pero expresado en hora de La Paz.
    $clave = openssl_pkey_new(['private_key_bits' => 2048, 'config' => config('siat.openssl_config')]);
    $csr = openssl_csr_new(['commonName' => 'prueba'], $clave, ['config' => config('siat.openssl_config')]);
    $x509 = openssl_csr_sign($csr, null, $clave, 1, ['config' => config('siat.openssl_config')]);
    openssl_x509_export($x509, $pem);
    $vence = Certificado::vencimientoDeX509($pem);

    expect($vence->getTimezone()->getName())->toBe('America/La_Paz')
        ->and($vence->getTimestamp())->toBe(openssl_x509_parse($pem)['validTo_time_t']);
});

// --- Filtro por defecto: este mes --------------------------------------------------

test('facturas y trabajos muestran por defecto el mes actual, y "Ver todo" la historia completa', function () {
    $this->actingAs(User::factory()->create());
    $this->travelTo(Carbon::parse('2026-10-15 10:00', 'America/La_Paz'));

    Factura::factory()->create(['comprador_razon_social' => 'COMPRADOR DE OCTUBRE', 'fecha_emision' => '2026-10-05 09:00']);
    Factura::factory()->create(['comprador_razon_social' => 'COMPRADOR DE SEPTIEMBRE', 'fecha_emision' => '2026-09-20 09:00']);

    $this->get(route('admin.facturas.index'))
        ->assertSee('COMPRADOR DE OCTUBRE')
        ->assertDontSee('COMPRADOR DE SEPTIEMBRE')
        ->assertSee('value="2026-10-01"', false)
        ->assertSee('value="2026-10-15"', false);

    $this->get(route('admin.facturas.index', ['desde' => '', 'hasta' => '']))
        ->assertSee('COMPRADOR DE OCTUBRE')
        ->assertSee('COMPRADOR DE SEPTIEMBRE');

    $nueva = Trabajo::create(['uuid' => (string) Str::uuid(), 'clase' => RenovarCufd::class, 'estado' => 'EXITOSO']);
    $vieja = Trabajo::create(['uuid' => (string) Str::uuid(), 'clase' => RenovarCufd::class, 'estado' => 'FALLIDO']);
    $vieja->forceFill(['created_at' => '2026-09-10 08:00'])->saveQuietly();

    $this->get(route('admin.trabajos.index'))->assertSee('Todos 1')->assertSee('Fallido 0');
    $this->get(route('admin.trabajos.index', ['desde' => '', 'hasta' => '']))->assertSee('Todos 2')->assertSee('Fallido 1');
});

test('buscar una factura puntual no queda limitado al mes', function () {
    $this->actingAs(User::factory()->create());
    $vieja = Factura::factory()->create(['cuf' => 'CUF-DEL-ANIO-PASADO', 'comprador_razon_social' => 'COMPRADOR VIEJO', 'fecha_emision' => now()->subYear()]);

    $this->get(route('admin.facturas.index', ['q' => 'CUF-DEL-ANIO-PASADO']))->assertSee('COMPRADOR VIEJO');
});

test('el detalle de una factura validada enlaza a su consulta en el SIAT', function () {
    $this->actingAs(User::factory()->create());
    $validada = Factura::factory()->create(['estado' => Factura::ESTADO_VALIDADA]);
    $pendiente = Factura::factory()->create(['estado' => Factura::ESTADO_PENDIENTE]);

    $this->get(route('admin.facturas.show', $validada))->assertOk()
        ->assertSee('Verificar en SIAT')
        ->assertSee('consulta/QR?nit='.$validada->empresa->nit.'&amp;cuf='.$validada->cuf.'&amp;numero='.$validada->numero_factura.'&amp;t=2', false);
    $this->get(route('admin.facturas.show', $pendiente))->assertOk()->assertDontSee('Verificar en SIAT');
});

test('el PDF del detalle usa el enlace publico firmado de la factura', function () {
    $this->actingAs(User::factory()->create());
    $factura = Factura::factory()->create(['estado' => Factura::ESTADO_VALIDADA, 'xml_firmado' => '<factura/>']);

    $this->get(route('admin.facturas.show', $factura))->assertOk()
        ->assertSee(e($factura->urlPublica('pdf')), false)
        ->assertSee('<svg class="icono"', false)
        ->assertDontSee(route('admin.facturas.pdf', $factura));
});

// --- Anular y revertir desde el panel ----------------------------------------------------

/**
 * Factura con su anulacion ya confirmada por el SIN.
 */
function facturaAnuladaConfirmada(): Factura
{
    $factura = Factura::factory()->create(['estado' => Factura::ESTADO_ANULADA]);
    FacturaAnulada::create([
        'factura_id' => $factura->id, 'motivo' => 1, 'anulada_en' => now(),
        'estado' => FacturaAnulada::ESTADO_CONFIRMADA, 'estado_anterior' => Factura::ESTADO_VALIDADA,
    ]);

    return $factura;
}

/**
 * El SIN responde a la reversion con la transaccion indicada.
 */
function sinRespondeReversion(bool $acepta): void
{
    $servicio = Mockery::mock(ServicioFacturacion::class);
    $servicio->shouldReceive('revertirAnulacion')->once()->andReturn((object) ['RespuestaServicioFacturacion' => (object) (
        $acepta
            ? ['transaccion' => true, 'codigoEstado' => 907]
            : ['transaccion' => false, 'mensajesList' => (object) ['codigo' => 1043, 'descripcion' => 'FACTURA NO ANULADA']]
    )]);
    test()->mock(FabricaServicios::class, fn ($mock) => $mock->shouldReceive('facturacion')->andReturn($servicio));
}

test('el panel anula una factura validada con un motivo del catalogo', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    Catalogo::factory()->create(['tipo' => 'motivos_anulacion', 'codigo_clasificador' => '1', 'descripcion' => 'FACTURA MAL EMITIDA']);
    $factura = Factura::factory()->create(['estado' => Factura::ESTADO_VALIDADA]);

    $this->get(route('admin.facturas.show', $factura))->assertSee('FACTURA MAL EMITIDA')->assertSee('Anular factura');

    $this->post(route('admin.facturas.anular', $factura), ['motivo' => 9])->assertSessionHasErrors('motivo');
    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_VALIDADA);

    $this->post(route('admin.facturas.anular', $factura), ['motivo' => 1])->assertSessionHas('estado');

    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_ANULADA)
        ->and($factura->anulacion->estado)->toBe(FacturaAnulada::ESTADO_PENDIENTE)
        ->and($factura->anulacion->estado_anterior)->toBe(Factura::ESTADO_VALIDADA);
    Queue::assertPushed(AnularFacturaEnSiat::class);
});

test('el panel revierte una anulacion confirmada y despues ya no deja anular de nuevo', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    Catalogo::factory()->create(['tipo' => 'motivos_anulacion', 'codigo_clasificador' => '1', 'descripcion' => 'FACTURA MAL EMITIDA']);
    $factura = facturaAnuladaConfirmada();
    sinRespondeReversion(true);

    $this->get(route('admin.facturas.show', $factura))->assertSee('Revertir anulacion')->assertDontSee('Anular factura');
    $this->post(route('admin.facturas.revertir', $factura))->assertSessionHas('estado');

    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_VALIDADA)
        ->and($factura->anulacion->fresh()->estado)->toBe(FacturaAnulada::ESTADO_REVERTIDA);
    Queue::assertPushed(NotificarWebhook::class, fn ($job) => $job->evento === 'factura.anulacion_revertida');

    // Una sola vez: ni el panel ni la API la vuelven a anular.
    $this->get(route('admin.facturas.show', $factura))
        ->assertDontSee('Anular factura')->assertDontSee('Revertir anulacion')->assertSee('ya se anulo y se revirtio una vez');
    $this->post(route('admin.facturas.anular', $factura), ['motivo' => 1])->assertSessionHas('error');
    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_VALIDADA);
    Queue::assertNotPushed(AnularFacturaEnSiat::class);
});

test('si el SIN rechaza la reversion la factura sigue anulada y se muestra el motivo', function () {
    $this->actingAs(User::factory()->create());
    $factura = facturaAnuladaConfirmada();
    sinRespondeReversion(false);

    $this->post(route('admin.facturas.revertir', $factura))->assertSessionHas('error', fn ($error) => str_contains($error, '[1043] FACTURA NO ANULADA'));

    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_ANULADA)
        ->and($factura->anulacion->fresh()->estado)->toBe(FacturaAnulada::ESTADO_CONFIRMADA);
});

test('el panel muestra el descuento global y la gift card de la factura', function () {
    $this->actingAs(User::factory()->create());
    $factura = Factura::factory()->create(['descuento_global' => 3.5, 'gift_card' => 2]);

    $this->get(route('admin.facturas.show', $factura))->assertSee('Descuento global / gift card')->assertSee('Bs 3.50 / Bs 2.00');
});

test('mientras el SIAT no confirma la anulacion la ficha consulta el estado y se recarga sola', function () {
    $this->actingAs(User::factory()->create());
    $factura = Factura::factory()->create(['estado' => Factura::ESTADO_ANULADA]);
    $anulacion = FacturaAnulada::create([
        'factura_id' => $factura->id, 'motivo' => 1, 'anulada_en' => now(),
        'estado' => FacturaAnulada::ESTADO_PENDIENTE, 'estado_anterior' => Factura::ESTADO_VALIDADA,
    ]);

    $this->get(route('admin.facturas.show', $factura))
        ->assertSee('Esperando que el SIAT confirme la anulacion')
        ->assertSee(json_encode(route('admin.facturas.estado', $factura)), false);
    $this->getJson(route('admin.facturas.estado', $factura))->assertExactJson(['estado' => 'ANULADA', 'anulacion' => 'PENDIENTE']);

    $anulacion->update(['estado' => FacturaAnulada::ESTADO_CONFIRMADA]);
    $this->get(route('admin.facturas.show', $factura))->assertDontSee('Esperando que el SIAT confirme')->assertSee('Revertir anulacion');
});

test('cada cliente de la lista enlaza a todas sus facturas', function () {
    $this->actingAs(User::factory()->create());
    $empresa = Empresa::factory()->create();
    Factura::factory()->for($empresa)->count(2)->create(['fecha_emision' => now()->subMonths(3)]);

    $url = route('admin.facturas.index', ['empresa' => $empresa->id, 'desde' => '', 'hasta' => '']);

    $this->get(route('admin.empresas.index'))->assertOk()
        ->assertSee(e($url), false)
        ->assertSee('Facturas (2)');
    // Sin el limite del mes actual: aparecen tambien las de hace meses.
    expect($this->get($url)->assertOk()->viewData('facturas')->total())->toBe(2);
});

// --- CAFC por empresa -----------------------------------------------------------------

test('el panel carga el CAFC de la empresa y lo muestra con su rango', function () {
    $this->actingAs(User::factory()->create());
    $empresa = Empresa::factory()->create();

    $this->get(route('admin.empresas.show', $empresa))->assertSee('sin CAFC');

    $this->post(route('admin.empresas.cafcs.store', $empresa), [
        'codigo' => '101486bc6731e', 'numero_inicio' => 1, 'numero_fin' => 1000,
    ])->assertRedirect(route('admin.empresas.show', $empresa))->assertSessionHas('estado');

    $cafc = $empresa->fresh()->cafcActivo;
    expect($cafc->codigo)->toBe('101486BC6731E')
        ->and($cafc->disponibles())->toBe(1000)
        ->and($cafc->usados())->toBe(0);

    $this->get(route('admin.empresas.show', $empresa))
        ->assertSee('101486BC6731E')
        ->assertSee('1 al 1000')
        ->assertSee('1000 números');
});

test('cargar otro CAFC desactiva el anterior y no toca los de otra empresa', function () {
    $this->actingAs(User::factory()->create());
    $empresa = Empresa::factory()->create();
    $anterior = Cafc::factory()->for($empresa)->create(['codigo' => 'VIEJO1']);
    $ajeno = Cafc::factory()->create();

    $this->post(route('admin.empresas.cafcs.store', $empresa), [
        'codigo' => 'NUEVO1', 'numero_inicio' => 1001, 'numero_fin' => 2000,
    ]);

    expect($anterior->fresh()->activo)->toBeFalse()
        ->and($ajeno->fresh()->activo)->toBeTrue()
        ->and($empresa->fresh()->cafcActivo->codigo)->toBe('NUEVO1');
});

test('el CAFC se valida: rango invertido, codigo repetido o con simbolos', function (array $datos, string $campo) {
    $this->actingAs(User::factory()->create());
    $empresa = Empresa::factory()->create();
    Cafc::factory()->for($empresa)->create(['codigo' => 'REPETIDO1']);

    $this->from(route('admin.empresas.show', $empresa))
        ->post(route('admin.empresas.cafcs.store', $empresa), $datos + ['codigo' => 'OTRO1', 'numero_inicio' => 1, 'numero_fin' => 10])
        ->assertSessionHasErrors($campo);

    expect($empresa->cafcs()->count())->toBe(1);
})->with([
    'rango invertido' => [['numero_inicio' => 10, 'numero_fin' => 5], 'numero_fin'],
    'repetido' => [['codigo' => 'REPETIDO1'], 'codigo'],
    'con simbolos' => [['codigo' => '1014-86'], 'codigo'],
]);

test('los errores de validacion se muestran en el panel', function () {
    $this->actingAs(User::factory()->create());
    $empresa = Empresa::factory()->create();

    $this->from(route('admin.empresas.show', $empresa))
        ->followingRedirects()
        ->post(route('admin.empresas.cafcs.store', $empresa), ['codigo' => 'X1', 'numero_inicio' => 10, 'numero_fin' => 5])
        ->assertSee('El fin del rango no puede ser menor que el inicio.');
});

test('un CAFC con el rango consumido se ve agotado', function () {
    $cafc = Cafc::factory()->make(['numero_inicio' => 1, 'numero_fin' => 1000, 'ultimo_numero' => 1000]);

    expect($cafc->usados())->toBe(1000)
        ->and($cafc->disponibles())->toBe(0)
        ->and($cafc->estaAgotado())->toBeTrue();
});

test('el formulario de empresa no pide el codigo interno, que el XML sigue llevando con su default', function () {
    $this->actingAs(User::factory()->create());
    $empresa = Empresa::factory()->create();

    $this->get(route('admin.empresas.create'))->assertOk()->assertDontSee('name="codigo_interno"', false);
    $this->get(route('admin.empresas.edit', $empresa))->assertOk()->assertDontSee('name="codigo_interno"', false);
    expect($empresa->fresh()->codigo_interno)->toBe('A');
});

test('el alta de empresa sugiere el codigo de sistema del proveedor', function () {
    $this->actingAs(User::factory()->create());
    config(['siat.proveedor.codigo_sistema' => '228BABC372923E714D2F7']);

    $this->get(route('admin.empresas.create'))->assertOk()
        ->assertSee('name="codigo_sistema"', false)
        ->assertSee('value="228BABC372923E714D2F7"', false);
});

test('la pantalla de codigos cierra todos sus contenedores', function () {
    $this->actingAs(User::factory()->create());
    $puntoVenta = PuntoVenta::factory()->create();
    Cufd::factory()->for($puntoVenta)->create(['version' => 'v2']);

    $html = $this->get(route('admin.puntos-venta.codigos', $puntoVenta))->assertOk()->getContent();

    // Un </div> de mas cerraba el contenido antes de tiempo y las tarjetas
    // de CUFD salian como columnas encima del encabezado.
    expect(preg_match_all('/<div\b/', $html))->toBe(substr_count($html, '</div>'));
});
