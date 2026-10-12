<?php

use App\Exceptions\SiatException;
use App\Jobs\EjecutarCasoPrueba;
use App\Jobs\EnviarFacturaAlSiat;
use App\Jobs\RenovarCufd;
use App\Jobs\VerificarEstadoFactura;
use App\Models\Cuis;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\PuntoVenta;
use App\Models\Trabajo;
use App\Models\User;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\GestorCodigos;
use App\Services\Siat\ServicioFacturacion;
use App\Services\Trabajos\RegistroTrabajos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Cola real en base de datos, como en produccion: los eventos que llenan
    // el historial son los del worker de verdad.
    config(['queue.default' => 'database']);
});

/**
 * Corre UN trabajo con el worker real.
 */
function correrUnTrabajo(): void
{
    Artisan::call('queue:work', ['--once' => true, '--queue' => 'facturas,default,piloto']);
}

test('un trabajo encolado y procesado queda EXITOSO con su factura y empresa', function () {
    $factura = Factura::factory()->validada()->create(); // ya validada: el job no hace nada

    EnviarFacturaAlSiat::dispatch($factura->id);

    expect(Trabajo::sole())
        ->estado->toBe(Trabajo::EN_COLA)
        ->cola->toBe('facturas')
        ->relacionado_tipo->toBe('factura')
        ->relacionado_id->toBe($factura->id)
        ->empresa_id->toBe($factura->empresa_id);

    correrUnTrabajo();

    $trabajo = Trabajo::sole();
    expect($trabajo->estado)->toBe(Trabajo::EXITOSO)
        ->and($trabajo->intentos)->toBe(1)
        ->and($trabajo->duracion_ms)->not->toBeNull()
        ->and($trabajo->nombre())->toBe('Envío de factura al SIN');
});

test('un trabajo que se reencola porque el SIN no responde queda REINTENTANDO', function () {
    $factura = Factura::factory()->create(['estado' => Factura::ESTADO_RECIBIDA]);
    $servicio = Mockery::mock(ServicioFacturacion::class);
    $servicio->shouldReceive('verificarEstado')->andThrow(new SiatException('SIAT caido'));
    $this->mock(FabricaServicios::class, fn ($mock) => $mock->shouldReceive('facturacion')->andReturn($servicio));

    VerificarEstadoFactura::dispatch($factura->id);
    correrUnTrabajo();

    expect(Trabajo::sole()->estado)->toBe(Trabajo::REINTENTANDO);
});

test('un trabajo que agota sus intentos queda FALLIDO con el error, y se puede reintentar', function () {
    $this->actingAs(User::factory()->create());
    $pv = PuntoVenta::factory()->create(['codigo_punto_venta' => 0]);
    Cuis::factory()->create(['punto_venta_id' => $pv->id]);
    $this->mock(GestorCodigos::class, fn ($mock) => $mock->shouldReceive('renovarCufd')->andThrow(new SiatException('El SIAT no entrego el CUFD')));

    RenovarCufd::dispatch($pv->id);
    foreach (range(1, 3) as $intento) {
        correrUnTrabajo();
    }

    $trabajo = Trabajo::sole();
    expect($trabajo->estado)->toBe(Trabajo::FALLIDO)
        ->and($trabajo->intentos)->toBe(3)
        ->and($trabajo->error)->toContain('El SIAT no entrego el CUFD')
        ->and(DB::table('failed_jobs')->count())->toBe(1);

    // Reintentar desde el panel: vuelve a la cola y sale de failed_jobs.
    $this->post(route('admin.trabajos.reintentar', $trabajo))->assertSessionHas('estado');

    expect($trabajo->fresh()->estado)->toBe(Trabajo::EN_COLA)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1);
});

test('descartar un fallido lo borra del historial y de failed_jobs', function () {
    $this->actingAs(User::factory()->create());
    $pv = PuntoVenta::factory()->create(['codigo_punto_venta' => 0]);
    Cuis::factory()->create(['punto_venta_id' => $pv->id]);
    $this->mock(GestorCodigos::class, fn ($mock) => $mock->shouldReceive('renovarCufd')->andThrow(new SiatException('x')));

    RenovarCufd::dispatch($pv->id);
    foreach (range(1, 3) as $intento) {
        correrUnTrabajo();
    }

    $this->delete(route('admin.trabajos.descartar', Trabajo::sole()))->assertSessionHas('estado');

    expect(Trabajo::count())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(0);
});

test('la pantalla separa por estado, con contadores, y oculta el piloto por defecto', function () {
    $this->actingAs(User::factory()->create());
    $empresa = Empresa::factory()->create(['nombre_comercial' => 'Ferreteria Sol']);
    $crear = fn (string $estado, string $clase, string $cola) => Trabajo::create([
        'uuid' => (string) Str::uuid(), 'clase' => $clase, 'cola' => $cola,
        'estado' => $estado, 'empresa_id' => $empresa->id, 'encolado_en' => now(),
    ]);
    $crear(Trabajo::EXITOSO, EnviarFacturaAlSiat::class, 'facturas');
    $crear(Trabajo::EXITOSO, EnviarFacturaAlSiat::class, 'facturas');
    $crear(Trabajo::FALLIDO, RenovarCufd::class, 'default');
    $crear(Trabajo::EXITOSO, EjecutarCasoPrueba::class, 'piloto');

    $this->get(route('admin.trabajos.index'))
        ->assertOk()
        ->assertSee('Todos 3')
        ->assertSee('Exitoso 2')
        ->assertSee('Fallido 1')
        ->assertDontSee('cola piloto')
        ->assertSee('Reintentar todos los fallidos')
        ->assertSee('http-equiv="refresh"', false);

    $this->get(route('admin.trabajos.index', ['estado' => Trabajo::FALLIDO]))
        ->assertSee('Renovación de CUFD')
        ->assertDontSee('cola facturas');

    $this->get(route('admin.trabajos.index', ['piloto' => 1]))->assertSee('Todos 4')->assertSee('cola piloto');

    $this->get(route('admin.trabajos.index', ['pausa' => 1]))->assertDontSee('http-equiv="refresh"', false);
});

test('el semaforo avisa si el worker o el scheduler estan apagados', function () {
    Cache::forget(RegistroTrabajos::LATIDO_WORKER);
    Cache::forget(RegistroTrabajos::LATIDO_SCHEDULER);
    DB::table('jobs')->insert(['queue' => 'facturas', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subMinutes(5)->getTimestamp(), 'created_at' => now()->subMinutes(5)->getTimestamp()]);

    $apagados = RegistroTrabajos::estadoProcesos();
    expect($apagados['worker']['activo'])->toBeFalse()
        ->and($apagados['worker']['texto'])->toContain('hay 1 trabajo(s) esperando')
        ->and($apagados['scheduler']['activo'])->toBeFalse();

    // El worker late al procesar; el scheduler, con su tarea de cada minuto.
    app(RegistroTrabajos::class)->latidoWorker();
    $this->artisan('schedule:test', ['--name' => 'latido-scheduler'])->assertSuccessful();

    $vivos = RegistroTrabajos::estadoProcesos();
    expect($vivos['worker']['activo'])->toBeTrue()->and($vivos['scheduler']['activo'])->toBeTrue();
});

test('el menu muestra un globo con los fallidos y el dashboard el resumen', function () {
    $this->actingAs(User::factory()->create());
    Trabajo::create(['uuid' => (string) Str::uuid(), 'clase' => RenovarCufd::class, 'cola' => 'default', 'estado' => Trabajo::FALLIDO]);

    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('class="globo"', false)
        ->assertSee('1 fallidos')
        ->assertSee('Worker APAGADO');
});

test('la limpieza borra exitosos de mas de 7 dias y fallidos de mas de 30', function () {
    $crear = fn (string $estado, int $dias) => Trabajo::create([
        'uuid' => (string) Str::uuid(), 'clase' => RenovarCufd::class, 'estado' => $estado,
    ])->forceFill(['updated_at' => now()->subDays($dias)])->saveQuietly();

    $crear(Trabajo::EXITOSO, 8);   // se va
    $crear(Trabajo::EXITOSO, 2);   // queda
    $crear(Trabajo::FALLIDO, 20);  // queda
    $crear(Trabajo::FALLIDO, 31);  // se va

    $this->artisan('siat:purgar-logs')->assertSuccessful();

    expect(Trabajo::orderBy('id')->get(['estado'])->pluck('estado')->all())->toBe([Trabajo::EXITOSO, Trabajo::FALLIDO]);
});
