<?php

use App\Jobs\EnviarFacturaAlSiat;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\LogSiat;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function logSiat(Empresa $empresa, int $diasAtras): LogSiat
{
    return LogSiat::create([
        'empresa_id' => $empresa->id,
        'servicio' => 'FacturacionCodigos',
        'operacion' => 'cufd',
        'xml_enviado' => '<peticion/>',
        'xml_recibido' => '<respuesta/>',
        'duracion_ms' => 120,
        'exitoso' => true,
        'created_at' => now()->subDays($diasAtras),
    ]);
}

test('la purga borra solo los logs fuera del periodo de retencion', function () {
    config()->set('siat.retencion_logs_dias', 30);
    $empresa = Empresa::factory()->create();

    $viejo = logSiat($empresa, 45);
    $reciente = logSiat($empresa, 5);

    $this->artisan('siat:purgar-logs')->assertSuccessful();

    expect(LogSiat::find($viejo->id))->toBeNull()
        ->and(LogSiat::find($reciente->id))->not->toBeNull();
});

test('la purga acepta los dias por opcion', function () {
    $empresa = Empresa::factory()->create();
    logSiat($empresa, 10);

    $this->artisan('siat:purgar-logs', ['--dias' => 5])->assertSuccessful();

    expect(LogSiat::count())->toBe(0);
});

test('la purga rechaza una retencion invalida', function () {
    $empresa = Empresa::factory()->create();
    logSiat($empresa, 100);

    $this->artisan('siat:purgar-logs', ['--dias' => 0])->assertFailed();

    // Nada se borro: mejor fallar que vaciar la auditoria por un cero.
    expect(LogSiat::count())->toBe(1);
});

// ---- Reintento de pendientes ------------------------------------------------

/**
 * Factura recien emitida que todavia no llego al SIN.
 */
function facturaPendienteDe(int $minutos): Factura
{
    $empresa = Empresa::factory()->enProduccion()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $puntoVenta = PuntoVenta::factory()->for($sucursal)->create();

    return Factura::factory()->create([
        'empresa_id' => $empresa->id,
        'punto_venta_id' => $puntoVenta->id,
        'estado' => Factura::ESTADO_PENDIENTE,
        'created_at' => now()->subMinutes($minutos),
    ]);
}

/**
 * Corre el cierre de la tarea programada tal cual la define routes/console.php.
 */
function correrReintentoDePendientes(): void
{
    collect(app(Schedule::class)->events())
        ->firstOrFail(fn ($evento) => $evento->description === 'reintentar-pendientes')
        ->run(app());
}

test('el reintento de pendientes ignora las facturas con un job todavia en vuelo', function () {
    Queue::fake();

    // El job de envio agota sus tres intentos en ~7,5 min antes de derivar a
    // contingencia: despachar de nuevo una factura mas nueva la mandaria dos
    // veces al SIN.
    facturaPendienteDe(2);

    correrReintentoDePendientes();

    Queue::assertNotPushed(EnviarFacturaAlSiat::class);
});

test('el reintento de pendientes si toma las facturas realmente atascadas', function () {
    Queue::fake();

    facturaPendienteDe(30);

    correrReintentoDePendientes();

    Queue::assertPushed(EnviarFacturaAlSiat::class, 1);
});
