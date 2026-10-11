<?php

use App\Models\Empresa;
use App\Models\Factura;
use App\Models\LogApi;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Models\TokenApi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- Autenticacion -------------------------------------------------------------

test('sin token, con uno inventado o con la vieja X-Api-Key la API responde 401', function (array $cabeceras) {
    $this->withHeaders($cabeceras)
        ->getJson('/api/v1/estado')
        ->assertUnauthorized()
        ->assertJson(['exito' => false, 'error' => 'TOKEN_INVALIDO']);
})->with([
    'sin token' => [[]],
    'token inventado' => [['Authorization' => 'Bearer 1|siat_inventado']],
    'API key vieja' => [['X-Api-Key' => 'clave-de-antes']],
]);

test('un token valido identifica a su empresa y marca su ultimo uso', function () {
    $empresa = Empresa::factory()->enProduccion()->create();
    $nuevo = $empresa->emitirTokenApi('Caja 1', 'caja');

    // Formato de Sanctum: {id}|{prefijo}{secreto}.
    expect($nuevo->plainTextToken)->toMatch('/^\d+\|siat_\w{40}/');

    $this->withToken($nuevo->plainTextToken)
        ->getJson('/api/v1/estado')
        ->assertOk()
        ->assertJsonPath('empresa.estado', Empresa::ESTADO_PRODUCCION);

    expect($nuevo->accessToken->fresh()->last_used_at)->not->toBeNull();
});

test('un token solo ve las facturas de su empresa', function () {
    $mia = Empresa::factory()->create();
    $ajena = Factura::factory()->for(Empresa::factory()->create())->create();

    $this->withToken($mia->emitirTokenApi('Caja', 'caja')->plainTextToken)
        ->getJson("/api/v1/facturas/{$ajena->cuf}")
        ->assertNotFound();
});

test('un token vencido o revocado deja de servir', function (string $caso) {
    $empresa = Empresa::factory()->create();
    $nuevo = $empresa->emitirTokenApi('Caja', 'caja', now()->addDay());

    $caso === 'vencido'
        ? $this->travel(2)->days()
        : $nuevo->accessToken->delete();

    $this->withToken($nuevo->plainTextToken)
        ->getJson('/api/v1/estado')
        ->assertUnauthorized();
})->with(['vencido', 'revocado']);

test('la sesion del panel no autentica la API', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/estado')
        ->assertUnauthorized();
});

// --- IPs permitidas ------------------------------------------------------------

test('un token con IPs permitidas rechaza las demas', function (string $ip, int $estado) {
    $token = Empresa::factory()->create()->emitirTokenApi('Caja', 'caja', null, ['200.87.1.10'])->plainTextToken;

    $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withToken($token)
        ->getJson('/api/v1/estado')
        ->assertStatus($estado);
})->with([
    'permitida' => ['200.87.1.10', 200],
    'otra' => ['10.0.0.9', 403],
]);

// --- Permisos ----------------------------------------------------------------------

test('cada perfil solo llega a lo que sus permisos permiten', function (string $perfil, string $metodo, string $ruta, bool $permitido) {
    $empresa = Empresa::factory()->create();
    $factura = Factura::factory()->for($empresa)->create(['estado' => Factura::ESTADO_PENDIENTE]);
    $ruta = str_replace('{cuf}', $factura->cuf, $ruta);

    $respuesta = $this->withToken($empresa->emitirTokenApi('T', $perfil)->plainTextToken)
        ->json($metodo, $ruta, ['motivo' => 1]);

    $permitido
        ? expect($respuesta->status())->not->toBe(403)
        : $respuesta->assertForbidden()->assertJson(['error' => 'PERMISO_INSUFICIENTE']);
})->with([
    'caja consulta facturas' => ['caja', 'GET', '/api/v1/facturas', true],
    'caja no anula' => ['caja', 'POST', '/api/v1/facturas/{cuf}/anular', false],
    'caja no crea puntos de venta' => ['caja', 'POST', '/api/v1/puntos-venta', false],
    'administracion anula' => ['administracion', 'POST', '/api/v1/facturas/{cuf}/anular', true],
    'consulta no emite' => ['consulta', 'POST', '/api/v1/facturas', false],
    'consulta lee catalogos' => ['consulta', 'GET', '/api/v1/catalogos/unidades_medida', true],
]);

test('un perfil inexistente no emite token', function () {
    Empresa::factory()->create()->emitirTokenApi('T', 'superusuario');
})->throws(InvalidArgumentException::class);

// --- Registro de llamadas --------------------------------------------------------

test('cada llamada queda en logs_api, tambien los rechazos', function () {
    $empresa = Empresa::factory()->create();
    $nuevo = $empresa->emitirTokenApi('Caja', 'caja');

    $this->withToken($nuevo->plainTextToken)->getJson('/api/v1/estado')->assertOk();
    // En los tests la app no se reinicia entre peticiones: el guard recordaria
    // la empresa de la llamada anterior.
    app('auth')->forgetGuards();
    $this->withToken('9|siat_falso')->getJson('/api/v1/estado')->assertUnauthorized();

    expect(LogApi::orderBy('id')->get(['empresa_id', 'token_id', 'metodo', 'ruta', 'estado_http'])->toArray())->toBe([
        ['empresa_id' => $empresa->id, 'token_id' => $nuevo->accessToken->id, 'metodo' => 'GET', 'ruta' => '/api/v1/estado', 'estado_http' => 200],
        ['empresa_id' => null, 'token_id' => null, 'metodo' => 'GET', 'ruta' => '/api/v1/estado', 'estado_http' => 401],
    ]);
});

test('la purga borra los logs_api fuera de retencion', function () {
    LogApi::create(['metodo' => 'GET', 'ruta' => '/viejo', 'estado_http' => 200, 'created_at' => now()->subDays(100)]);
    LogApi::create(['metodo' => 'GET', 'ruta' => '/nuevo', 'estado_http' => 200, 'created_at' => now()]);

    $this->artisan('siat:purgar-logs')->assertSuccessful();

    expect(LogApi::pluck('ruta')->all())->toBe(['/nuevo']);
});

// --- Panel -------------------------------------------------------------------------

test('el panel genera el token de la empresa, lo muestra una sola vez y lo lista', function () {
    $this->actingAs(User::factory()->create());
    $empresa = Empresa::factory()->create(['nombre_comercial' => 'Ferreteria Sol']);

    $this->post(route('admin.empresas.tokens.store', $empresa), [
        'perfil' => 'caja',
        'vence_el' => now()->addMonth()->toDateString(),
        'ips_permitidas' => '200.87.1.10, 200.87.1.11',
    ])->assertRedirect(route('admin.empresas.api', $empresa))
        ->assertSessionHas('token_api', fn (string $t) => str_contains($t, '|siat_'));

    $token = TokenApi::sole();
    expect($token->name)->toBe('Token de Ferreteria Sol')
        ->and($token->abilities)->toBe(config('siat.api.perfiles.caja.permisos'))
        ->and($token->ips_permitidas)->toBe(['200.87.1.10', '200.87.1.11'])
        ->and($token->expires_at->isFuture())->toBeTrue();

    $this->get(route('admin.empresas.api', $empresa))
        ->assertSee('Caja / POS')
        ->assertSee('200.87.1.10, 200.87.1.11')
        ->assertSee('nunca')
        ->assertSee('Regenerar token');
});

test('regenerar deja un solo token: el anterior deja de funcionar', function () {
    $this->actingAs(User::factory()->create());
    $empresa = Empresa::factory()->create();
    $anterior = $empresa->emitirTokenApi('Viejo', 'caja')->plainTextToken;

    $this->post(route('admin.empresas.tokens.store', $empresa), ['perfil' => 'administracion'])
        ->assertSessionHas('estado', fn (string $m) => str_contains($m, 'el anterior ya NO funciona'))
        ->assertSessionHas('token_api');

    expect(TokenApi::count())->toBe(1)
        ->and(TokenApi::sole()->abilities)->toBe(config('siat.api.perfiles.administracion.permisos'));

    $this->withToken($anterior)->getJson('/api/v1/estado')->assertUnauthorized();
});

test('regenerar el token de una empresa no toca el de otra', function () {
    $this->actingAs(User::factory()->create());
    $otra = Empresa::factory()->create();
    $otra->emitirTokenApi('Suyo', 'caja');

    $this->post(route('admin.empresas.tokens.store', Empresa::factory()->create()), ['perfil' => 'caja']);

    expect($otra->tokens()->count())->toBe(1)->and(TokenApi::count())->toBe(2);
});

test('el panel rechaza una IP invalida', function () {
    $this->actingAs(User::factory()->create());
    $empresa = Empresa::factory()->create();

    $this->post(route('admin.empresas.tokens.store', $empresa), [
        'perfil' => 'caja', 'ips_permitidas' => '999.1.1.1',
    ])->assertSessionHas('error', "La IP '999.1.1.1' no es valida.");

    expect(TokenApi::count())->toBe(0);
});

test('revocar borra el token, pero no el de otra empresa', function () {
    $this->actingAs(User::factory()->create());
    $empresa = Empresa::factory()->create();
    $propio = $empresa->emitirTokenApi('Caja', 'caja')->accessToken;
    $ajeno = Empresa::factory()->create()->emitirTokenApi('Caja', 'caja')->accessToken;

    $this->delete(route('admin.empresas.tokens.destroy', [$empresa, $ajeno]))->assertNotFound();
    $this->delete(route('admin.empresas.tokens.destroy', [$empresa, $propio]))
        ->assertRedirect(route('admin.empresas.api', $empresa));

    expect(TokenApi::pluck('id')->all())->toBe([$ajeno->id]);
});

test('la consola de la API cuenta los tokens activos de cada empresa', function () {
    $this->actingAs(User::factory()->create());
    $empresa = Empresa::factory()->create(['nombre_comercial' => 'Ferreteria Sol']);
    $empresa->emitirTokenApi('Caja', 'caja');
    $empresa->emitirTokenApi('Vieja', 'caja', now()->subDay());

    $this->get(route('admin.api'))
        ->assertOk()
        ->assertSee('Authorization: Bearer', false)
        ->assertSee('facturas:emitir');

    expect(Empresa::withCount(['tokens as activos' => fn ($q) => $q->where('expires_at', '>', now())->orWhereNull('expires_at')])->find($empresa->id)->activos)->toBe(1);
});

// --- Pagina "Acceso API" de la empresa ------------------------------------------------

test('la pagina de acceso API reune conexion, tokens, webhook y llamadas, en orden', function () {
    config(['siat.webhooks.secreto' => 'maestro']);
    $this->actingAs(User::factory()->create());
    $empresa = Empresa::factory()->create(['webhook_url' => 'https://cliente.example/hook']);
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0, 'nombre' => 'Caja principal']);
    $empresa->emitirTokenApi('Caja centro', 'caja');
    LogApi::create(['empresa_id' => $empresa->id, 'metodo' => 'GET', 'ruta' => '/api/v1/estado', 'estado_http' => 200, 'ip' => '200.87.1.10', 'created_at' => now()]);

    $this->get(route('admin.empresas.api', $empresa))
        ->assertOk()
        ->assertSeeInOrder(['1. Datos de conexion', '2. Token', '3. Webhook', '4. Ultimas llamadas'])
        ->assertSee('/api/v1')
        ->assertSee('sucursal: 0, punto_venta: 0')
        ->assertSee('Caja / POS')
        ->assertSee($empresa->secretoWebhook())
        ->assertSee('200.87.1.10');
});

test('la ficha ya no mezcla los tokens: muestra un resumen y el acceso', function () {
    $this->actingAs(User::factory()->create());
    $empresa = Empresa::factory()->create();
    $empresa->emitirTokenApi('Caja centro', 'caja');

    $this->get(route('admin.empresas.show', $empresa))
        ->assertOk()
        ->assertSee('token activo')
        ->assertSeeInOrder(['<h2>Acceso API</h2>', '<h2>Certificado digital (.p12)</h2>'], false)
        ->assertSee(route('admin.empresas.api', $empresa))
        ->assertDontSee('Regenerar token')
        ->assertDontSee('Generar token');
});

test('el webhook se guarda y se quita desde acceso API, con validacion del destino', function () {
    config(['siat.webhooks.permitir_destinos_privados' => false]);
    $this->actingAs(User::factory()->create());
    $empresa = Empresa::factory()->create();

    $this->put(route('admin.empresas.api.webhook', $empresa), ['webhook_url' => 'http://10.0.0.5/hook'])
        ->assertSessionHasErrors('webhook_url');
    expect($empresa->fresh()->webhook_url)->toBeNull();

    $this->put(route('admin.empresas.api.webhook', $empresa), ['webhook_url' => 'https://93.184.216.34/hook'])
        ->assertRedirect(route('admin.empresas.api', $empresa));
    expect($empresa->fresh()->webhook_url)->toBe('https://93.184.216.34/hook');

    $this->put(route('admin.empresas.api.webhook', $empresa), ['webhook_url' => ''])
        ->assertSessionHas('estado', 'Webhook quitado: la empresa ya no recibe avisos.');
    expect($empresa->fresh()->webhook_url)->toBeNull();
});

test('editar la empresa ya no toca el webhook', function () {
    $this->actingAs(User::factory()->create());
    $empresa = Empresa::factory()->create(['webhook_url' => 'https://93.184.216.34/hook']);

    $this->put(route('admin.empresas.update', $empresa), [
        'nombre_comercial' => 'Nuevo nombre', 'razon_social' => $empresa->razon_social, 'nit' => $empresa->nit,
        'codigo_ambiente' => 2, 'codigo_modalidad' => 1, 'estado' => $empresa->estado,
    ])->assertRedirect(route('admin.empresas.show', $empresa));

    expect($empresa->fresh()->webhook_url)->toBe('https://93.184.216.34/hook')
        ->and($empresa->fresh()->nombre_comercial)->toBe('Nuevo nombre');
});
