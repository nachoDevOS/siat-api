<?php

use App\Jobs\EnviarFacturaAlSiat;
use App\Models\Cufd;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Prepara una empresa en produccion con un punto de venta que tiene CUFD
 * vigente, y devuelve [empresa, apiKeyEnClaro].
 *
 * @return array{0: Empresa, 1: string}
 */
function empresaLista(): array
{
    $clave = Str::random(48);
    $empresa = Empresa::factory()->enProduccion()->create([
        'api_key_hash' => hash('sha256', $clave),
    ]);

    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $pv = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);
    Cufd::factory()->for($pv)->create();

    return [$empresa, $clave];
}

/**
 * @return array<string, mixed>
 */
function ventaEjemplo(string $referencia = 'VTA-001'): array
{
    return [
        'sucursal' => 0,
        'punto_venta' => 0,
        'referencia_externa' => $referencia,
        'comprador' => [
            'tipo_documento' => 1,
            'numero_documento' => '1023456',
            'razon_social' => 'JUAN PEREZ',
            'email' => 'juan@correo.com',
        ],
        'metodo_pago' => 1,
        'usuario' => 'caja-01',
        'items' => [[
            'codigo_producto_sin' => 99100,
            'codigo_interno' => 'TOR-14',
            'descripcion' => 'Tornillo autoperforante',
            'cantidad' => 100,
            'unidad_medida' => 57,
            'precio_unitario' => 1.5,
        ]],
    ];
}

test('sin API key devuelve 401', function () {
    $this->postJson('/api/v1/facturas', ventaEjemplo())
        ->assertStatus(401)
        ->assertJsonPath('error', 'API_KEY_INVALIDA');
});

test('empresa fuera de produccion no puede facturar', function () {
    $clave = Str::random(48);
    Empresa::factory()->create(['api_key_hash' => hash('sha256', $clave)]); // EN_REGISTRO

    $this->withHeaders(['X-Api-Key' => $clave])
        ->postJson('/api/v1/facturas', ventaEjemplo())
        ->assertStatus(403)
        ->assertJsonPath('error', 'EMPRESA_NO_HABILITADA');
});

test('emite una factura y responde con el CUF', function () {
    Queue::fake();
    [, $clave] = empresaLista();

    $respuesta = $this->withHeaders(['X-Api-Key' => $clave])
        ->postJson('/api/v1/facturas', ventaEjemplo());

    $respuesta->assertStatus(201)
        ->assertJsonPath('exito', true)
        ->assertJsonPath('factura.estado', 'PENDIENTE');

    expect($respuesta->json('factura.cuf'))->not->toBeEmpty();
    expect(Factura::count())->toBe(1);

    // El envio al SIAT se hace en segundo plano.
    Queue::assertPushed(EnviarFacturaAlSiat::class);
});

test('la idempotencia evita duplicar la factura', function () {
    Queue::fake();
    [, $clave] = empresaLista();

    $primera = $this->withHeaders(['X-Api-Key' => $clave])->postJson('/api/v1/facturas', ventaEjemplo('VTA-99'));
    $segunda = $this->withHeaders(['X-Api-Key' => $clave])->postJson('/api/v1/facturas', ventaEjemplo('VTA-99'));

    // Misma referencia: una sola factura y el mismo CUF.
    expect(Factura::count())->toBe(1)
        ->and($segunda->json('factura.cuf'))->toBe($primera->json('factura.cuf'));
});

test('un JSON invalido devuelve 422', function () {
    [, $clave] = empresaLista();

    $this->withHeaders(['X-Api-Key' => $clave])
        ->postJson('/api/v1/facturas', ['items' => []])
        ->assertStatus(422);
});

test('el endpoint de estado reporta que puede facturar', function () {
    [, $clave] = empresaLista();

    $this->withHeaders(['X-Api-Key' => $clave])
        ->getJson('/api/v1/estado')
        ->assertOk()
        ->assertJsonPath('puede_facturar', true);
});
