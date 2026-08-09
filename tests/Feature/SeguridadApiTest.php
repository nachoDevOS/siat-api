<?php

use App\Exceptions\FacturaInvalidaException;
use App\Models\Cufd;
use App\Models\Empresa;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Factura\EmisorFactura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Empresa en produccion con punto de venta y CUFD vigente.
 *
 * @return array{0: Empresa, 1: string}
 */
function empresaConClave(): array
{
    $clave = Str::random(48);
    $empresa = Empresa::factory()->enProduccion()->create([
        'api_key_hash' => hash('sha256', $clave),
    ]);

    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $puntoVenta = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);
    Cufd::factory()->for($puntoVenta)->create();

    return [$empresa, $clave];
}

test('el limite de peticiones sale de config y no de env', function () {
    // Con env() y config:cache esto quedaba en 0 y rechazaba todo.
    config()->set('siat.api.rate_limit', 2);
    [, $clave] = empresaConClave();

    $cabeceras = ['X-Api-Key' => $clave];

    $this->withHeaders($cabeceras)->getJson('/api/v1/estado')->assertOk();
    $this->withHeaders($cabeceras)->getJson('/api/v1/estado')->assertOk();

    $this->withHeaders($cabeceras)->getJson('/api/v1/estado')
        ->assertStatus(429)
        ->assertJsonPath('error', 'DEMASIADAS_PETICIONES');
});

test('con el limite por defecto una peticion normal pasa', function () {
    [, $clave] = empresaConClave();

    expect((int) config('siat.api.rate_limit'))->toBeGreaterThan(0);

    $this->withHeaders(['X-Api-Key' => $clave])->getJson('/api/v1/estado')->assertOk();
});

test('sacar la empresa de produccion invalida el cache de su API key', function () {
    [$empresa, $clave] = empresaConClave();
    $cabeceras = ['X-Api-Key' => $clave];

    // Primera llamada: la empresa queda cacheada por su hash.
    $this->withHeaders($cabeceras)->getJson('/api/v1/estado')->assertOk();

    $empresa->update(['estado' => Empresa::ESTADO_OBSERVADO]);

    // Sin invalidacion, seguiria facturando hasta que venciera el TTL.
    $this->withHeaders($cabeceras)->postJson('/api/v1/facturas', [])
        ->assertStatus(403)
        ->assertJsonPath('error', 'EMPRESA_NO_HABILITADA');
});

test('rotar la API key deja de aceptar la anterior', function () {
    [$empresa, $clave] = empresaConClave();

    $this->withHeaders(['X-Api-Key' => $clave])->getJson('/api/v1/estado')->assertOk();

    $nueva = Str::random(48);
    $empresa->update(['api_key_hash' => hash('sha256', $nueva)]);

    $this->withHeaders(['X-Api-Key' => $clave])->getJson('/api/v1/estado')->assertStatus(401);
    $this->withHeaders(['X-Api-Key' => $nueva])->getJson('/api/v1/estado')->assertOk();
});

test('el login bloquea tras varios intentos fallidos', function () {
    User::factory()->create(['email' => 'admin@admin.com', 'password' => bcrypt('password')]);

    foreach (range(1, 5) as $intento) {
        $this->post('/login', ['email' => 'admin@admin.com', 'password' => 'mala']);
    }

    $this->post('/login', ['email' => 'admin@admin.com', 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('el emisor rechaza una venta que no pasa las reglas locales', function () {
    Queue::fake();
    [$empresa] = empresaConClave();

    $venta = [
        'sucursal' => 0,
        'punto_venta' => 0,
        'comprador' => ['tipo_documento' => 1, 'numero_documento' => '123', 'razon_social' => ''],
        'metodo_pago' => 1,
        'items' => [],
    ];

    expect(fn () => app(EmisorFactura::class)->emitir($empresa, $venta))
        ->toThrow(FacturaInvalidaException::class);
});
