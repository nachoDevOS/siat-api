<?php

use App\Exceptions\SiatException;
use App\Jobs\EnviarPaqueteContingencia;
use App\Models\Empresa;
use App\Models\EventoSignificativo;
use App\Models\Factura;
use App\Models\Paquete;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\ServicioCodigos;
use App\Services\Siat\ServicioOperaciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * La contingencia era de ida y no de vuelta: derivar() se llamaba desde el job
 * de envio, pero recuperar() SOLO se llamaba desde el paso 16 del piloto. En
 * operacion normal nada cerraba el evento ni armaba el paquete, asi que una
 * factura en CONTINGENCIA no llegaba nunca al SIN.
 *
 * 'siat:recuperar-contingencia' cierra ese circuito.
 */

/**
 * Evento de contingencia abierto con una factura acumulada.
 *
 * @return array{0: EventoSignificativo, 1: Factura}
 */
function contingenciaAbierta(): array
{
    $empresa = Empresa::factory()->enProduccion()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $puntoVenta = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);

    $evento = EventoSignificativo::create([
        'empresa_id' => $empresa->id,
        'punto_venta_id' => $puntoVenta->id,
        'codigo_evento' => 1,
        'descripcion' => 'Corte de conexion con el SIAT',
        'fecha_inicio' => now()->subHour(),
        'estado' => 'ABIERTO',
    ]);

    $factura = Factura::factory()->create([
        'empresa_id' => $empresa->id,
        'punto_venta_id' => $puntoVenta->id,
        'estado' => Factura::ESTADO_CONTINGENCIA,
        'tipo_emision' => Factura::EMISION_CONTINGENCIA,
        'xml_firmado' => '<factura/>',
    ]);

    return [$evento, $factura];
}

/**
 * Doble de la fabrica con la sonda de fecha/hora y el registro de evento.
 */
function fabricaDeRecuperacion(bool $siatResponde, bool $eventoAceptado = true): void
{
    // La sonda es verificarComunicacion: es la unica operacion del WSDL que no
    // pide parametros, asi que no depende de que haya CUIS vigente.
    $codigos = Mockery::mock(ServicioCodigos::class);

    if ($siatResponde) {
        $codigos->shouldReceive('verificarComunicacion')->andReturn((object) ['transaccion' => true]);
    } else {
        $codigos->shouldReceive('verificarComunicacion')->andThrow(new SiatException('SIAT caido'));
    }

    $operaciones = Mockery::mock(ServicioOperaciones::class);
    $operaciones->shouldReceive('registrarEvento')->andReturn([
        'RespuestaServicioFacturacion' => $eventoAceptado
            ? ['transaccion' => true, 'codigoRecepcion' => 'EV-123']
            : ['transaccion' => false, 'mensajesList' => ['codigo' => 1, 'descripcion' => 'EVENTO INVALIDO']],
    ]);

    test()->mock(FabricaServicios::class, function ($mock) use ($codigos, $operaciones) {
        $mock->shouldReceive('codigos')->andReturn($codigos);
        $mock->shouldReceive('operaciones')->andReturn($operaciones);
    });
}

test('con el SIAT de vuelta se cierra el evento y se despacha el paquete', function () {
    Queue::fake();
    [$evento, $factura] = contingenciaAbierta();
    fabricaDeRecuperacion(siatResponde: true);

    $this->artisan('siat:recuperar-contingencia')->assertSuccessful();

    expect($evento->fresh()->estado)->toBe('CERRADO')
        ->and($evento->fresh()->codigo_recepcion)->toBe('EV-123')
        ->and($evento->fresh()->fecha_fin)->not->toBeNull();

    // La factura queda congelada en un paquete: es lo que impide que una
    // factura que entre a contingencia despues se de por enviada sin viajar.
    $paquete = Paquete::where('evento_id', $evento->id)->first();

    expect($paquete)->not->toBeNull()
        ->and($paquete->cantidad_facturas)->toBe(1)
        ->and($factura->fresh()->paquete_id)->toBe($paquete->id);

    Queue::assertPushed(EnviarPaqueteContingencia::class);
});

test('si el SIAT sigue caido el evento queda abierto y no se arma paquete', function () {
    Queue::fake();
    [$evento] = contingenciaAbierta();
    fabricaDeRecuperacion(siatResponde: false);

    $this->artisan('siat:recuperar-contingencia')->assertSuccessful();

    expect($evento->fresh()->estado)->toBe('ABIERTO')
        ->and(Paquete::count())->toBe(0);

    Queue::assertNotPushed(EnviarPaqueteContingencia::class);
});

test('si el SIN rechaza el registro del evento no se manda el paquete', function () {
    Queue::fake();
    [$evento] = contingenciaAbierta();
    fabricaDeRecuperacion(siatResponde: true, eventoAceptado: false);

    $this->artisan('siat:recuperar-contingencia')->assertSuccessful();

    // Sin el evento registrado el SIN rechazaria el paquete igual: se deja
    // abierto para reintentar en la corrida siguiente.
    expect($evento->fresh()->estado)->toBe('ABIERTO')
        ->and(Paquete::count())->toBe(0);

    Queue::assertNotPushed(EnviarPaqueteContingencia::class);
});

test('sin eventos abiertos el comando no hace nada', function () {
    Queue::fake();

    $this->artisan('siat:recuperar-contingencia')
        ->expectsOutputToContain('No hay eventos de contingencia abiertos.')
        ->assertSuccessful();
});
