<?php

use App\Jobs\EntregarWebhook;
use App\Jobs\NotificarWebhook;
use App\Jobs\RenovarCufd;
use App\Models\Certificado;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEntrega;
use App\Services\Contingencia\GestorContingencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Sin DNS en las pruebas: los destinos de ejemplo no se resuelven.
    config(['siat.webhooks.permitir_destinos_privados' => true]);
});

function tokenWebhooks(Empresa $empresa, string $perfil = 'administracion'): string
{
    return $empresa->emitirTokenApi('Test', $perfil)->plainTextToken;
}

// --- Administrar destinos ------------------------------------------------------------

test('se registra un destino, el secreto se ve una vez y se puede rotar', function () {
    $empresa = Empresa::factory()->create();
    $token = tokenWebhooks($empresa);

    $creado = $this->withToken($token)->postJson('/api/v1/webhooks', [
        'url' => 'https://erp.cliente.bo/hook', 'eventos' => ['factura.*', 'siat.conexion_perdida'], 'descripcion' => 'ERP',
    ])->assertCreated()->assertJsonPath('webhook.eventos', ['factura.*', 'siat.conexion_perdida']);

    $id = $creado->json('webhook.id');
    $secreto = $creado->json('webhook.secreto');
    expect($secreto)->toStartWith('whsec_');

    $this->withToken($token)->getJson("/api/v1/webhooks/{$id}")->assertSuccessful()->assertJsonMissingPath('webhook.secreto');
    $lista = $this->withToken($token)->getJson('/api/v1/webhooks')->assertJsonCount(1, 'webhooks');
    expect($lista->json('eventos_disponibles'))->toHaveKey('cufd.renovacion_fallida');

    $rotado = $this->withToken($token)->postJson("/api/v1/webhooks/{$id}/rotar-secreto")->assertSuccessful()->json('webhook.secreto');
    expect($rotado)->not->toBe($secreto)->and(WebhookEndpoint::find($id)->secreto)->toBe($rotado);

    $this->withToken($token)->putJson("/api/v1/webhooks/{$id}", ['activo' => false])->assertJsonPath('webhook.activo', false);
    $this->withToken($token)->deleteJson("/api/v1/webhooks/{$id}")->assertSuccessful();
    expect(WebhookEndpoint::count())->toBe(0);
});

test('se rechazan eventos desconocidos, destinos internos, ajenos y el exceso', function () {
    $empresa = Empresa::factory()->create();
    $token = tokenWebhooks($empresa);

    $this->withToken($token)->postJson('/api/v1/webhooks', ['url' => 'https://x.bo', 'eventos' => ['factura.inventado']])
        ->assertUnprocessable()->assertJsonValidationErrors('eventos.0');

    config(['siat.webhooks.permitir_destinos_privados' => false]);
    $this->withToken($token)->postJson('/api/v1/webhooks', ['url' => 'https://127.0.0.1/hook', 'eventos' => ['*']])
        ->assertUnprocessable()->assertJsonValidationErrors('url');
    config(['siat.webhooks.permitir_destinos_privados' => true]);

    $ajeno = WebhookEndpoint::factory()->create();
    $this->withToken($token)->getJson("/api/v1/webhooks/{$ajeno->id}")->assertNotFound();

    WebhookEndpoint::factory()->count(WebhookEndpoint::MAXIMO_POR_EMPRESA)->create(['empresa_id' => $empresa->id]);
    $this->withToken($token)->postJson('/api/v1/webhooks', ['url' => 'https://x.bo', 'eventos' => ['*']])
        ->assertConflict()->assertJsonPath('error', 'LIMITE_WEBHOOKS');

    // Una caja no administra webhooks.
    app('auth')->forgetGuards();
    $this->withToken(tokenWebhooks($empresa, 'caja'))->getJson('/api/v1/webhooks')->assertForbidden();
});

// --- Reparto y entrega ---------------------------------------------------------------

test('un evento de factura va a cada destino que lo escucha, firmado con su secreto', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    $factura = Factura::factory()->validada()->create();
    $todo = WebhookEndpoint::factory()->create(['empresa_id' => $factura->empresa_id, 'url' => 'https://a.bo/h', 'eventos' => ['*']]);
    WebhookEndpoint::factory()->create(['empresa_id' => $factura->empresa_id, 'url' => 'https://b.bo/h', 'eventos' => ['factura.*']]);
    WebhookEndpoint::factory()->create(['empresa_id' => $factura->empresa_id, 'url' => 'https://c.bo/h', 'eventos' => ['siat.*']]);
    WebhookEndpoint::factory()->create(['empresa_id' => $factura->empresa_id, 'url' => 'https://d.bo/h', 'eventos' => ['*'], 'activo' => false]);

    app()->call([new NotificarWebhook($factura->id, 'factura.validada'), 'handle']);

    expect(WebhookEntrega::count())->toBe(2)
        ->and(WebhookEntrega::where('estado', WebhookEntrega::ESTADO_ENTREGADA)->count())->toBe(2);

    $entrega = WebhookEntrega::where('webhook_endpoint_id', $todo->id)->first();
    $cuerpo = json_decode($entrega->cuerpo, true);
    expect($cuerpo['evento'])->toBe('factura.validada')
        ->and($cuerpo['id'])->toBe($entrega->uuid)
        ->and($cuerpo['datos']['cuf'])->toBe($factura->cuf)
        ->and($entrega->codigo_http)->toBe(200)
        ->and($entrega->intentos)->toBe(1);

    Http::assertSent(fn (PeticionHttp $r) => $r->url() === 'https://a.bo/h'
        && $r->header('X-Siat-Entrega')[0] === $entrega->uuid
        && $r->header('X-Siat-Signature')[0] === 'sha256='.hash_hmac('sha256', $r->body(), $todo->secreto));
});

test('una entrega rechazada queda registrada y se reintenta; agotada, FALLIDA', function () {
    Http::fake(['*' => Http::response('caido', 503)]);
    $entrega = WebhookEntrega::factory()->create();

    expect(fn () => app()->call([new EntregarWebhook($entrega->id), 'handle']))->toThrow(RuntimeException::class);
    expect($entrega->fresh())->estado->toBe(WebhookEntrega::ESTADO_PENDIENTE)->codigo_http->toBe(503)->error->toBe('caido');

    (new EntregarWebhook($entrega->id))->failed(null);
    expect($entrega->fresh()->estado)->toBe(WebhookEntrega::ESTADO_FALLIDA);
});

test('el historial se consulta y una entrega se reenvia con el mismo id', function () {
    Queue::fake();
    $destino = WebhookEndpoint::factory()->create();
    $fallida = WebhookEntrega::factory()->create(['webhook_endpoint_id' => $destino->id, 'estado' => WebhookEntrega::ESTADO_FALLIDA, 'codigo_http' => 500]);
    WebhookEntrega::factory()->create(['webhook_endpoint_id' => $destino->id, 'estado' => WebhookEntrega::ESTADO_ENTREGADA]);
    $token = tokenWebhooks($destino->empresa);

    $this->withToken($token)->getJson("/api/v1/webhooks/{$destino->id}/entregas?estado=FALLIDA")
        ->assertSuccessful()->assertJsonCount(1, 'entregas')->assertJsonPath('entregas.0.id', $fallida->uuid);

    $this->withToken($token)->postJson("/api/v1/webhooks/entregas/{$fallida->uuid}/reenviar")->assertAccepted();

    expect($fallida->fresh()->estado)->toBe(WebhookEntrega::ESTADO_PENDIENTE);
    Queue::assertPushed(EntregarWebhook::class, fn ($job) => $job->entregaId === $fallida->id);

    // La de otra empresa no se toca.
    $ajena = WebhookEntrega::factory()->create();
    $this->withToken($token)->postJson("/api/v1/webhooks/entregas/{$ajena->uuid}/reenviar")->assertNotFound();
});

// --- Eventos operativos --------------------------------------------------------------

test('la contingencia avisa cuando el SIAT cae y cuando vuelve', function () {
    Queue::fake();
    $empresa = Empresa::factory()->create();
    $pv = PuntoVenta::factory()->for(Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 2]))->create(['codigo_punto_venta' => 1]);
    WebhookEndpoint::factory()->create(['empresa_id' => $empresa->id, 'eventos' => ['siat.*']]);
    $factura = Factura::factory()->create(['empresa_id' => $empresa->id, 'punto_venta_id' => $pv->id]);
    $gestor = app(GestorContingencia::class);

    $evento = $gestor->derivar($factura);
    $gestor->derivar(Factura::factory()->create(['empresa_id' => $empresa->id, 'punto_venta_id' => $pv->id]));
    $gestor->recuperar($evento->fresh());

    $eventos = WebhookEntrega::orderBy('id')->pluck('evento')->all();
    $cierre = json_decode(WebhookEntrega::latest('id')->first()->cuerpo, true)['datos'];

    // Un solo aviso de caida aunque caigan dos facturas.
    expect($eventos)->toBe(['siat.conexion_perdida', 'siat.conexion_restablecida'])
        ->and($cierre)->toMatchArray(['sucursal' => 2, 'punto_venta' => 1, 'facturas_por_enviar' => 2]);
});

test('una renovacion de CUFD fallida avisa al cliente', function () {
    Queue::fake();
    $pv = PuntoVenta::factory()->create();
    WebhookEndpoint::factory()->create(['empresa_id' => $pv->sucursal->empresa_id, 'eventos' => ['cufd.renovacion_fallida']]);

    (new RenovarCufd($pv->id))->failed(new RuntimeException('SIN caido'));

    $entrega = WebhookEntrega::sole();
    expect($entrega->evento)->toBe('cufd.renovacion_fallida')
        ->and(json_decode($entrega->cuerpo, true)['datos']['error'])->toBe('SIN caido');
});

test('el certificado por vencer avisa solo en los dias marcados', function (int $dias, bool $avisa) {
    Queue::fake();
    $this->travelTo(now()->setTime(10, 0));
    $certificado = Certificado::factory()->create(['vence_el' => now()->addDays($dias)->toDateString(), 'activo' => true]);
    WebhookEndpoint::factory()->create(['empresa_id' => $certificado->empresa_id, 'eventos' => ['certificado.por_vencer']]);

    $this->artisan('siat:avisar-certificados')->assertSuccessful();

    expect(WebhookEntrega::count())->toBe($avisa ? 1 : 0);
})->with([
    '30 dias' => [30, true],
    '20 dias' => [20, false],
    '7 dias' => [7, true],
    'vencido' => [-2, true],
]);
