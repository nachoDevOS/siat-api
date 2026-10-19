<?php

use App\Exceptions\SiatException;
use App\Jobs\AnularFacturaEnSiat;
use App\Jobs\NotificarWebhook;
use App\Models\Catalogo;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\FacturaAnulada;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\ServicioFacturacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Factura de la empresa en el estado pedido, con el catalogo de motivos de
 * anulacion cargado y, opcional, su registro de anulacion.
 *
 * @param  array<string, mixed>|null  $anulacion
 */
function facturaParaAnular(string $estado = Factura::ESTADO_VALIDADA, ?array $anulacion = null): Factura
{
    Queue::fake();
    Catalogo::factory()->create(['tipo' => 'motivos_anulacion', 'codigo_clasificador' => '1', 'descripcion' => 'FACTURA MAL EMITIDA']);

    $empresa = Empresa::factory()->enProduccion()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $pv = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);
    $factura = Factura::factory()->create(['empresa_id' => $empresa->id, 'punto_venta_id' => $pv->id, 'estado' => $estado]);

    if ($anulacion !== null) {
        FacturaAnulada::create(array_merge([
            'factura_id' => $factura->id, 'motivo' => 1, 'anulada_en' => now(),
            'estado' => FacturaAnulada::ESTADO_CONFIRMADA, 'estado_anterior' => Factura::ESTADO_VALIDADA,
        ], $anulacion));
    }

    return $factura;
}

function tokenAnulacion(Factura $factura, string $perfil = 'administracion'): string
{
    return $factura->empresa->emitirTokenApi('Test', $perfil)->plainTextToken;
}

/**
 * El SIN simulado para la reversion: responde lo indicado o lanza.
 */
function sinRevierte(object $respuesta): void
{
    $servicio = Mockery::mock(ServicioFacturacion::class);
    $llamada = $servicio->shouldReceive('revertirAnulacion');
    $respuesta instanceof Throwable ? $llamada->andThrow($respuesta) : $llamada->andReturn($respuesta);

    test()->mock(FabricaServicios::class, fn ($mock) => $mock->shouldReceive('facturacion')->andReturn($servicio));
}

// --- Revertir ----------------------------------------------------------------------

test('revertir una anulacion confirmada deja la factura vigente y avisa', function () {
    $factura = facturaParaAnular(Factura::ESTADO_ANULADA, []);
    sinRevierte((object) ['RespuestaServicioFacturacion' => (object) ['transaccion' => true, 'codigoEstado' => 907]]);

    $this->withToken(tokenAnulacion($factura))
        ->postJson("/api/v1/facturas/{$factura->cuf}/revertir-anulacion")
        ->assertSuccessful()
        ->assertJsonPath('factura.estado', Factura::ESTADO_VALIDADA)
        ->assertJsonPath('factura.anulacion.estado', FacturaAnulada::ESTADO_REVERTIDA);

    // Una sola vez: ya no se puede volver a anular por servicio web.
    $this->withToken(tokenAnulacion($factura))
        ->postJson("/api/v1/facturas/{$factura->cuf}/anular", ['motivo' => 1])
        ->assertConflict()
        ->assertJsonPath('error', 'FACTURA_NO_ANULABLE');

    Queue::assertPushed(NotificarWebhook::class, fn ($job) => $job->evento === 'factura.anulacion_revertida');
});

test('no se revierte lo que no esta confirmado por servicio web', function (string $estado, ?array $anulacion, string $mensaje) {
    $factura = facturaParaAnular($estado, $anulacion);
    test()->mock(FabricaServicios::class, fn ($mock) => $mock->shouldNotReceive('facturacion'));

    $this->withToken(tokenAnulacion($factura))
        ->postJson("/api/v1/facturas/{$factura->cuf}/revertir-anulacion")
        ->assertConflict()
        ->assertJsonPath('error', 'FACTURA_NO_REVERTIBLE')
        ->assertJsonPath('mensaje', $mensaje);
})->with([
    'vigente' => [Factura::ESTADO_VALIDADA, null, 'La factura no esta anulada.'],
    'sin confirmar' => [Factura::ESTADO_ANULADA, ['estado' => FacturaAnulada::ESTADO_PENDIENTE], 'La anulacion todavia no esta confirmada por el SIN: espere el webhook factura.anulada.'],
    'administrativa' => [Factura::ESTADO_ANULADA, ['tipo' => FacturaAnulada::TIPO_ADMINISTRATIVA, 'numero_resolucion' => 'RA-1'], 'Una anulacion administrativa no se revierte por servicio web.'],
]);

test('si el SIN rechaza o no responde la reversion, la factura sigue anulada', function (object $respuesta) {
    $factura = facturaParaAnular(Factura::ESTADO_ANULADA, []);
    sinRevierte($respuesta);

    $this->withToken(tokenAnulacion($factura))
        ->postJson("/api/v1/facturas/{$factura->cuf}/revertir-anulacion")
        ->assertStatus(502)
        ->assertJsonPath('error', 'SIAT_NO_REVIRTIO');

    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_ANULADA)
        ->and($factura->anulacion->fresh()->estado)->toBe(FacturaAnulada::ESTADO_CONFIRMADA);
})->with([
    'rechazo' => [(object) ['RespuestaServicioFacturacion' => (object) ['transaccion' => false, 'mensajesList' => (object) ['codigo' => 1, 'descripcion' => 'YA REVERTIDA']]]],
    'caido' => [new SiatException('timeout')],
]);

// --- Anulacion administrativa ------------------------------------------------------

test('la anulacion administrativa queda confirmada sin llamar al SIAT', function () {
    $factura = facturaParaAnular();
    test()->mock(FabricaServicios::class, fn ($mock) => $mock->shouldNotReceive('facturacion'));

    $this->withToken(tokenAnulacion($factura))
        ->postJson("/api/v1/facturas/{$factura->cuf}/anulacion-administrativa", ['motivo' => 1, 'resolucion' => 'RA-2026-0042'])
        ->assertSuccessful()
        ->assertJsonPath('mensaje', 'Anulacion administrativa registrada.');

    $anulacion = $factura->fresh()->anulacion;

    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_ANULADA)
        ->and($anulacion->tipo)->toBe(FacturaAnulada::TIPO_ADMINISTRATIVA)
        ->and($anulacion->numero_resolucion)->toBe('RA-2026-0042')
        ->and($anulacion->estado)->toBe(FacturaAnulada::ESTADO_CONFIRMADA);

    Queue::assertNotPushed(AnularFacturaEnSiat::class);
    Queue::assertPushed(NotificarWebhook::class, fn ($job) => $job->evento === 'factura.anulada');

    // La API la muestra con su resolucion.
    $this->withToken(tokenAnulacion($factura, 'consulta'))
        ->getJson("/api/v1/facturas/{$factura->cuf}")
        ->assertJsonPath('factura.anulacion.tipo', 'ADMINISTRATIVA')
        ->assertJsonPath('factura.anulacion.numero_resolucion', 'RA-2026-0042');
});

test('una factura ya revertida igual admite la anulacion administrativa', function () {
    $factura = facturaParaAnular(Factura::ESTADO_VALIDADA, ['estado' => FacturaAnulada::ESTADO_REVERTIDA]);

    $this->withToken(tokenAnulacion($factura))
        ->postJson("/api/v1/facturas/{$factura->cuf}/anulacion-administrativa", ['motivo' => 1, 'resolucion' => 'RA-9'])
        ->assertSuccessful();

    expect($factura->fresh()->anulacion->tipo)->toBe(FacturaAnulada::TIPO_ADMINISTRATIVA);
});

test('la anulacion administrativa valida forma, estado y permiso', function (string $caso, int $estado) {
    $factura = facturaParaAnular($caso === 'pendiente' ? Factura::ESTADO_PENDIENTE : Factura::ESTADO_VALIDADA);

    $token = tokenAnulacion($factura, $caso === 'caja' ? 'caja' : 'administracion');
    $cuerpo = match ($caso) {
        'sin resolucion' => ['motivo' => 1],
        'motivo fuera de catalogo' => ['motivo' => 99, 'resolucion' => 'RA-1'],
        default => ['motivo' => 1, 'resolucion' => 'RA-1'],
    };

    $this->withToken($token)
        ->postJson("/api/v1/facturas/{$factura->cuf}/anulacion-administrativa", $cuerpo)
        ->assertStatus($estado);

    expect($factura->fresh()->estado)->not->toBe(Factura::ESTADO_ANULADA);
})->with([
    ['sin resolucion', 422],
    ['motivo fuera de catalogo', 422],
    ['pendiente', 409],
    ['caja', 403],
]);
