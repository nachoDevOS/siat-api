<?php

use App\Jobs\NotificarWebhook;
use App\Models\Empresa;
use App\Models\Factura;
use App\Services\Webhooks\DestinoWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * IP publica literal: evita depender del DNS dentro de la suite.
 */
const WEBHOOK_PUBLICO = 'https://93.184.216.34/avisos';

function facturaConWebhook(?string $url): Factura
{
    $empresa = Empresa::factory()->enProduccion()->create(['webhook_url' => $url]);

    return Factura::factory()->validada()->create(['empresa_id' => $empresa->id]);
}

test('un destino interno se rechaza', function (string $url) {
    expect(app(DestinoWebhook::class)->esAceptable($url))->toBeFalse();
})->with([
    'loopback' => 'https://127.0.0.1/hook',
    'metadatos cloud' => 'https://169.254.169.254/latest/meta-data/',
    'red privada' => 'https://10.0.0.5/hook',
    'sin https' => 'http://93.184.216.34/hook',
    'esquema raro' => 'file:///etc/passwd',
    'basura' => 'no-es-una-url',
]);

test('un destino publico por https se acepta', function () {
    expect(app(DestinoWebhook::class)->esAceptable(WEBHOOK_PUBLICO))->toBeTrue();
});

test('en desarrollo se puede permitir un destino local', function () {
    config()->set('siat.webhooks.permitir_destinos_privados', true);

    expect(app(DestinoWebhook::class)->esAceptable('http://localhost:9000/hook'))->toBeTrue();
});

test('el job no llama a un webhook que apunta a la red interna', function () {
    Http::fake();
    $factura = facturaConWebhook('https://127.0.0.1/hook');

    app(NotificarWebhook::class, ['facturaId' => $factura->id, 'evento' => 'factura.validada'])
        ->handle(app(DestinoWebhook::class));

    Http::assertNothingSent();
});

test('el job firma el cuerpo con HMAC-SHA256', function () {
    Http::fake();
    config()->set('siat.webhooks.secreto', 'secreto-de-prueba');

    $factura = facturaConWebhook(WEBHOOK_PUBLICO);

    app(NotificarWebhook::class, ['facturaId' => $factura->id, 'evento' => 'factura.validada'])
        ->handle(app(DestinoWebhook::class));

    Http::assertSent(function (Request $peticion) {
        $esperada = 'sha256='.hash_hmac('sha256', $peticion->body(), 'secreto-de-prueba');

        return $peticion->header('X-Siat-Signature')[0] === $esperada
            && $peticion->header('X-Siat-Evento')[0] === 'factura.validada';
    });
});

test('sin webhook configurado el job no hace nada', function () {
    Http::fake();
    $factura = facturaConWebhook(null);

    app(NotificarWebhook::class, ['facturaId' => $factura->id, 'evento' => 'factura.validada'])
        ->handle(app(DestinoWebhook::class));

    Http::assertNothingSent();
});
