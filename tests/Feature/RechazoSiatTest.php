<?php

use App\Exceptions\SiatException;
use App\Jobs\AnularFacturaEnSiat;
use App\Jobs\EnviarFacturaAlSiat;
use App\Jobs\EnviarPaqueteContingencia;
use App\Jobs\NotificarWebhook;
use App\Models\Empresa;
use App\Models\EventoSignificativo;
use App\Models\Factura;
use App\Models\FacturaAnulada;
use App\Models\Paquete;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Services\Contingencia\ArmadorPaquete;
use App\Services\Contingencia\GestorContingencia;
use App\Services\Factura\GeneradorPdf;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\RespuestaSiat;
use App\Services\Siat\ServicioFacturacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * El SIN NO usa SoapFault para rechazar un documento: responde HTTP 200 con
 * 'transaccion' en false y el motivo dentro de 'mensajesList'. Antes eso se
 * leia igual que una aceptacion, asi que una factura rechazada quedaba marcada
 * como enviada con el codigo de recepcion vacio y el motivo se perdia.
 *
 * Este archivo fija esa lectura y lo que cada job hace con ella.
 */

/**
 * Factura ya emitida, lista para que el job la transmita.
 */
function facturaTransmitible(string $estado = Factura::ESTADO_PENDIENTE): Factura
{
    $empresa = Empresa::factory()->enProduccion()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $puntoVenta = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);

    return Factura::factory()->create([
        'empresa_id' => $empresa->id,
        'punto_venta_id' => $puntoVenta->id,
        'estado' => $estado,
        'ruta_pdf' => 'siat/pdf/ya-existe.pdf',
        'xml_firmado' => '<factura/>',
    ]);
}

function servicioQueResponde(string $operacion, mixed $respuesta): void
{
    $servicio = Mockery::mock(ServicioFacturacion::class);
    $servicio->shouldReceive($operacion)->andReturn($respuesta);

    test()->mock(FabricaServicios::class, function ($mock) use ($servicio) {
        $mock->shouldReceive('facturacion')->andReturn($servicio);
    });
}

// ---- Lectura de la respuesta -----------------------------------------------

test('transaccion en false es un rechazo por mas que haya codigo de recepcion', function () {
    $respuesta = RespuestaSiat::desde([
        'RespuestaServicioFacturacion' => [
            'transaccion' => false,
            'codigoRecepcion' => '999',
            'mensajesList' => ['codigo' => 905, 'descripcion' => 'HASH INVALIDO'],
        ],
    ]);

    expect($respuesta->aceptada)->toBeFalse()
        ->and($respuesta->motivo())->toBe('[905] HASH INVALIDO');
});

test('varios mensajes se juntan en un solo motivo legible', function () {
    $respuesta = RespuestaSiat::desde([
        'RespuestaServicioFacturacion' => [
            'transaccion' => false,
            'mensajesList' => [
                ['codigo' => 1, 'descripcion' => 'FIRMA INVALIDA'],
                ['codigo' => 2, 'descripcion' => 'CUFD VENCIDO'],
            ],
        ],
    ]);

    expect($respuesta->motivo())->toBe('[1] FIRMA INVALIDA | [2] CUFD VENCIDO');
});

test('sin campo transaccion se acepta cuando hay codigo de recepcion', function () {
    expect(RespuestaSiat::desde(['RespuestaServicioFacturacion' => ['codigoRecepcion' => '123']])->aceptada)
        ->toBeTrue();

    expect(RespuestaSiat::desde(['RespuestaServicioFacturacion' => ['codigoRecepcion' => null]])->aceptada)
        ->toBeFalse();
});

// ---- Recepcion de factura --------------------------------------------------

test('una factura rechazada por el SIN queda OBSERVADA y no va a contingencia', function () {
    Queue::fake();
    $factura = facturaTransmitible();

    servicioQueResponde('recepcionarFactura', [
        'RespuestaServicioFacturacion' => [
            'transaccion' => false,
            'codigoEstado' => '904',
            'mensajesList' => ['codigo' => 904, 'descripcion' => 'DOCUMENTO NO CUMPLE EL ESQUEMA'],
        ],
    ]);

    (new EnviarFacturaAlSiat($factura->id))->handle(
        app(GeneradorPdf::class),
        app(GestorContingencia::class),
        app(FabricaServicios::class),
    );

    // Contingencia es para cuando el SIN NO responde. Aca respondio: el
    // problema es el documento, y reintentarlo daria el mismo rechazo.
    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_OBSERVADA)
        ->and($factura->fresh()->codigo_estado_siat)->toBe('904')
        ->and(EventoSignificativo::count())->toBe(0);

    Queue::assertPushed(NotificarWebhook::class);
});

// ---- Anulacion -------------------------------------------------------------

test('si el SIN rechaza la anulacion la factura vuelve a su estado anterior', function () {
    Queue::fake();
    $factura = facturaTransmitible(Factura::ESTADO_ANULADA);

    $anulacion = FacturaAnulada::create([
        'factura_id' => $factura->id,
        'motivo' => 1,
        'anulada_en' => now(),
        'estado' => FacturaAnulada::ESTADO_PENDIENTE,
        'estado_anterior' => Factura::ESTADO_VALIDADA,
    ]);

    servicioQueResponde('anular', [
        'RespuestaServicioFacturacion' => [
            'transaccion' => false,
            'mensajesList' => ['codigo' => 995, 'descripcion' => 'FUERA DE PLAZO DE ANULACION'],
        ],
    ]);

    (new AnularFacturaEnSiat($factura->id))->handle(app(FabricaServicios::class));

    // La factura sigue vigente ante el SIN: dejarla ANULADA de este lado seria
    // mentirle al cliente.
    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_VALIDADA)
        ->and($anulacion->fresh()->estado)->toBe(FacturaAnulada::ESTADO_RECHAZADA)
        ->and($anulacion->fresh()->motivo_rechazo)->toContain('FUERA DE PLAZO');
});

test('una anulacion aceptada queda confirmada con su codigo de recepcion', function () {
    Queue::fake();
    $factura = facturaTransmitible(Factura::ESTADO_ANULADA);

    $anulacion = FacturaAnulada::create([
        'factura_id' => $factura->id,
        'motivo' => 1,
        'anulada_en' => now(),
        'estado' => FacturaAnulada::ESTADO_PENDIENTE,
        'estado_anterior' => Factura::ESTADO_VALIDADA,
    ]);

    servicioQueResponde('anular', [
        'RespuestaServicioFacturacion' => ['transaccion' => true, 'codigoRecepcion' => '77'],
    ]);

    (new AnularFacturaEnSiat($factura->id))->handle(app(FabricaServicios::class));

    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_ANULADA)
        ->and($anulacion->fresh()->estado)->toBe(FacturaAnulada::ESTADO_CONFIRMADA)
        ->and($anulacion->fresh()->codigo_recepcion)->toBe('77');
});

// ---- Paquete de contingencia -----------------------------------------------

test('un paquete rechazado no se marca enviado ni libera sus facturas', function () {
    $factura = facturaTransmitible(Factura::ESTADO_CONTINGENCIA);

    $paquete = Paquete::create([
        'empresa_id' => $factura->empresa_id,
        'punto_venta_id' => $factura->punto_venta_id,
        'cantidad_facturas' => 1,
        'estado' => 'PENDIENTE',
    ]);
    $factura->update(['paquete_id' => $paquete->id]);

    servicioQueResponde('recepcionarPaquete', [
        'RespuestaServicioFacturacion' => [
            'transaccion' => false,
            'mensajesList' => ['codigo' => 901, 'descripcion' => 'EVENTO NO REGISTRADO'],
        ],
    ]);

    $job = (new EnviarPaqueteContingencia($paquete->id))->withFakeQueueInteractions();
    $job->job->attempts = $job->tries;

    expect(fn () => $job->handle(app(ArmadorPaquete::class), app(FabricaServicios::class)))
        ->toThrow(SiatException::class, 'EVENTO NO REGISTRADO');

    // Las facturas siguen siendo validas; solo falta volver a transmitirlas.
    expect($paquete->fresh()->estado)->toBe('PENDIENTE')
        ->and($factura->fresh()->estado)->toBe(Factura::ESTADO_CONTINGENCIA);
});
