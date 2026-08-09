<?php

use App\Models\Empresa;
use App\Models\LogSiat;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
