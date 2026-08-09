<?php

use App\Jobs\AnularFacturaEnSiat;
use App\Models\Cufd;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\FacturaAnulada;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Crea una factura de una empresa en produccion y devuelve
 * [factura, apiKeyEnClaro].
 *
 * @return array{0: Factura, 1: string}
 */
function facturaAnulable(string $estado = Factura::ESTADO_VALIDADA): array
{
    $clave = Str::random(48);
    $empresa = Empresa::factory()->enProduccion()->create([
        'api_key_hash' => hash('sha256', $clave),
    ]);

    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $puntoVenta = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);
    $cufd = Cufd::factory()->for($puntoVenta)->create();

    $factura = Factura::factory()->create([
        'empresa_id' => $empresa->id,
        'punto_venta_id' => $puntoVenta->id,
        'cufd_id' => $cufd->id,
        'estado' => $estado,
    ]);

    return [$factura, $clave];
}

test('anular registra la anulacion y encola el envio al SIAT', function () {
    Queue::fake();
    [$factura, $clave] = facturaAnulable();

    $this->withHeaders(['X-Api-Key' => $clave])
        ->postJson("/api/v1/facturas/{$factura->cuf}/anular", ['motivo' => 1])
        ->assertOk()
        ->assertJsonPath('exito', true);

    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_ANULADA);
    expect(FacturaAnulada::where('factura_id', $factura->id)->exists())->toBeTrue();

    // Antes esto se quedaba en local: la anulacion nunca llegaba al SIN.
    Queue::assertPushed(AnularFacturaEnSiat::class, fn ($job) => $job->facturaId === $factura->id);
});

test('una factura que el SIN todavia no conoce no se puede anular', function () {
    Queue::fake();
    [$factura, $clave] = facturaAnulable(Factura::ESTADO_PENDIENTE);

    $this->withHeaders(['X-Api-Key' => $clave])
        ->postJson("/api/v1/facturas/{$factura->cuf}/anular", ['motivo' => 1])
        ->assertStatus(409)
        ->assertJsonPath('error', 'FACTURA_NO_ANULABLE');

    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_PENDIENTE);
    Queue::assertNothingPushed();
});

test('repetir la anulacion no vuelve a encolar el envio', function () {
    Queue::fake();
    [$factura, $clave] = facturaAnulable();

    $cabeceras = ['X-Api-Key' => $clave];
    $url = "/api/v1/facturas/{$factura->cuf}/anular";

    $this->withHeaders($cabeceras)->postJson($url, ['motivo' => 1])->assertOk();
    $this->withHeaders($cabeceras)->postJson($url, ['motivo' => 1])->assertOk();

    expect(FacturaAnulada::where('factura_id', $factura->id)->count())->toBe(1);
    Queue::assertPushed(AnularFacturaEnSiat::class, 1);
});

test('no se puede anular la factura de otra empresa', function () {
    [$factura] = facturaAnulable();

    $claveAjena = Str::random(48);
    Empresa::factory()->enProduccion()->create(['api_key_hash' => hash('sha256', $claveAjena)]);

    $this->withHeaders(['X-Api-Key' => $claveAjena])
        ->postJson("/api/v1/facturas/{$factura->cuf}/anular", ['motivo' => 1])
        ->assertStatus(404);

    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_VALIDADA);
});
