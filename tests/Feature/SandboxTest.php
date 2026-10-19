<?php

use App\Models\Certificado;
use App\Models\Cufd;
use App\Models\Empresa;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Empresa con sucursal 0, punto de venta 0, CUFD y certificado.
 *
 * @param  array<string, mixed>  $atributos
 */
function empresaLista(array $atributos): Empresa
{
    $empresa = Empresa::factory()->create($atributos);
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $pv = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);
    Cufd::factory()->create(['punto_venta_id' => $pv->id]);
    Certificado::factory()->for($empresa)->firmable()->create();

    return $empresa;
}

/**
 * @return array<string, mixed>
 */
function ventaSandbox(): array
{
    return [
        'sucursal' => 0,
        'punto_venta' => 0,
        'comprador' => ['tipo_documento' => 1, 'numero_documento' => '1234567', 'razon_social' => 'JUAN PEREZ'],
        'metodo_pago' => 1,
        'items' => [['codigo_producto_sin' => 83141, 'descripcion' => 'X', 'cantidad' => 1, 'unidad_medida' => 58, 'precio_unitario' => 10]],
    ];
}

function tokenSandbox(Empresa $empresa): string
{
    return $empresa->emitirTokenApi('Test', 'administracion')->plainTextToken;
}

// --- Emitir en el piloto -------------------------------------------------------------

test('una empresa del piloto en pruebas emite por la API sin esperar a produccion', function (string $estado) {
    Queue::fake();
    $empresa = empresaLista(['codigo_ambiente' => 2, 'estado' => $estado]);

    $this->withToken(tokenSandbox($empresa))->postJson('/api/v1/facturas', ventaSandbox())->assertCreated();
})->with([Empresa::ESTADO_EN_PRUEBAS, Empresa::ESTADO_PILOTO_APROBADO, Empresa::ESTADO_PRODUCCION]);

test('no emiten: piloto en registro u observado, ni produccion sin habilitar', function (int $ambiente, string $estado, string $mensaje) {
    $empresa = empresaLista(['codigo_ambiente' => $ambiente, 'estado' => $estado]);

    $this->withToken(tokenSandbox($empresa))->postJson('/api/v1/facturas', ventaSandbox())
        ->assertForbidden()
        ->assertJsonPath('error', 'EMPRESA_NO_HABILITADA')
        ->assertJsonPath('mensaje', $mensaje);
})->with([
    'piloto en registro' => [2, Empresa::ESTADO_EN_REGISTRO, 'La empresa de pruebas todavia no esta lista para emitir: el proveedor tiene que pasarla a EN_PRUEBAS.'],
    'piloto observado' => [2, Empresa::ESTADO_OBSERVADO, 'La empresa de pruebas todavia no esta lista para emitir: el proveedor tiene que pasarla a EN_PRUEBAS.'],
    'produccion en pruebas' => [1, Empresa::ESTADO_EN_PRUEBAS, 'La empresa aun no esta habilitada para facturar en produccion.'],
]);

test('GET /estado dice el ambiente y si lo emitido tiene validez fiscal', function (int $ambiente, string $estado, string $nombre, bool $validez, bool $puede) {
    $empresa = empresaLista(['codigo_ambiente' => $ambiente, 'estado' => $estado]);

    $this->withToken(tokenSandbox($empresa))->getJson('/api/v1/estado')
        ->assertSuccessful()
        ->assertJsonPath('empresa.ambiente', $nombre)
        ->assertJsonPath('empresa.validez_fiscal', $validez)
        ->assertJsonPath('puede_facturar', $puede);
})->with([
    'sandbox' => [2, Empresa::ESTADO_EN_PRUEBAS, 'PILOTO', false, true],
    'produccion' => [1, Empresa::ESTADO_PRODUCCION, 'PRODUCCION', true, true],
    'produccion sin habilitar' => [1, Empresa::ESTADO_PILOTO_APROBADO, 'PRODUCCION', true, false],
]);

// --- Tokens por ambiente -------------------------------------------------------------

test('el token muestra su ambiente y sigue autenticando', function (int $ambiente, string $prefijo) {
    $empresa = empresaLista(['codigo_ambiente' => $ambiente, 'estado' => Empresa::ESTADO_PRODUCCION]);
    $token = tokenSandbox($empresa);

    expect(explode('|', $token)[1])->toStartWith($prefijo);

    $this->withToken($token)->getJson('/api/v1/estado')->assertSuccessful();
})->with([
    'piloto' => [2, 'siat_test_'],
    'produccion' => [1, 'siat_live_'],
]);

test('cambiar el ambiente revoca el token: uno de pruebas no factura en produccion', function () {
    $empresa = empresaLista(['codigo_ambiente' => 2, 'estado' => Empresa::ESTADO_PILOTO_APROBADO]);
    $token = tokenSandbox($empresa);
    $this->actingAs(User::factory()->create());

    $datos = fn (int $ambiente) => [
        'nombre_comercial' => $empresa->nombre_comercial, 'razon_social' => $empresa->razon_social,
        'nit' => $empresa->nit, 'codigo_ambiente' => $ambiente, 'codigo_modalidad' => 1,
        'estado' => Empresa::ESTADO_PRODUCCION,
    ];

    // Mismo ambiente: el token sigue.
    $this->put(route('admin.empresas.update', $empresa), $datos(2))->assertRedirect(route('admin.empresas.show', $empresa));
    expect($empresa->tokens()->count())->toBe(1);

    $this->put(route('admin.empresas.update', $empresa), $datos(1))
        ->assertRedirect(route('admin.empresas.api', $empresa))
        ->assertSessionHas('estado', fn ($m) => str_contains($m, 'REVOCADO'));

    expect($empresa->tokens()->count())->toBe(0);

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/estado')->assertUnauthorized();
});

// --- Crear sandbox desde el panel ----------------------------------------------------

test('el sandbox copia la empresa al piloto con certificado de prueba y sin secretos de produccion', function () {
    $empresa = empresaLista(['codigo_ambiente' => 1, 'estado' => Empresa::ESTADO_PRODUCCION, 'token_delegado' => 'JWT-PROD', 'webhook_url' => 'https://ventas.cliente.bo/hook']);
    Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 3]);
    PuntoVenta::factory()->for($empresa->sucursales()->where('codigo_sucursal', 0)->first())->create(['codigo_punto_venta' => 7]);
    $this->actingAs(User::factory()->create());

    $this->post(route('admin.empresas.sandbox', $empresa))->assertRedirect();

    $sandbox = $empresa->fresh()->sandbox;

    expect($sandbox)->not->toBeNull()
        ->and($sandbox->nit)->toBe($empresa->nit)
        ->and($sandbox->codigo_sistema)->toBe($empresa->codigo_sistema)
        ->and($sandbox->nombre_comercial)->toBe("{$empresa->nombre_comercial} (sandbox)")
        ->and($sandbox->esSandbox())->toBeTrue()
        ->and($sandbox->estado)->toBe(Empresa::ESTADO_EN_PRUEBAS)
        ->and($sandbox->puedeEmitirPorApi())->toBeTrue()
        ->and($sandbox->token_delegado)->toBeNull()
        ->and($sandbox->webhook_url)->toBeNull()
        ->and($sandbox->sucursales()->orderBy('codigo_sucursal')->pluck('codigo_sucursal')->all())->toBe([0, 3])
        // Solo el punto de venta implicito: el 7 no existe en el piloto.
        ->and(PuntoVenta::whereHas('sucursal', fn ($q) => $q->where('empresa_id', $sandbox->id))->pluck('codigo_punto_venta')->all())->toBe([0, 0])
        ->and($sandbox->certificadoActivo->esDePrueba())->toBeTrue()
        // La empresa real no se toca.
        ->and($empresa->fresh()->token_delegado)->toBe('JWT-PROD');
});

test('no se crea un sandbox de un sandbox ni dos del mismo NIT', function () {
    $empresa = empresaLista(['codigo_ambiente' => 1, 'estado' => Empresa::ESTADO_PRODUCCION]);
    $this->actingAs(User::factory()->create());
    $this->post(route('admin.empresas.sandbox', $empresa));

    $this->post(route('admin.empresas.sandbox', $empresa))
        ->assertSessionHas('error', 'Ya existe una empresa con este NIT en el ambiente piloto.');
    $this->post(route('admin.empresas.sandbox', $empresa->fresh()->sandbox))
        ->assertSessionHas('error', 'Esta empresa ya trabaja en el ambiente piloto: no necesita sandbox.');

    expect(Empresa::count())->toBe(2);
});
