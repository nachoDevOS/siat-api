<?php

use App\Exceptions\SiatException;
use App\Jobs\EnviarFacturaAlSiat;
use App\Jobs\EnviarPaqueteContingencia;
use App\Jobs\VerificarEstadoFactura;
use App\Models\Empresa;
use App\Models\EventoSignificativo;
use App\Models\Factura;
use App\Models\Paquete;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Services\Contingencia\ArmadorPaquete;
use App\Services\Contingencia\GestorContingencia;
use App\Services\Factura\GeneradorPdf;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\ServicioFacturacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Sustituye la fabrica por un doble que devuelve el servicio indicado, para
 * poder probar la logica de los jobs sin un SIAT real al otro lado.
 */
function fabricaQueDevuelve(ServicioFacturacion $servicio): void
{
    test()->mock(FabricaServicios::class, function ($mock) use ($servicio) {
        $mock->shouldReceive('facturacion')->andReturn($servicio);
    });
}

/**
 * Factura lista para enviar. Trae ruta_pdf puesta para que el job no gaste
 * tiempo renderizando el PDF en cada prueba.
 */
function facturaParaEnviar(): Factura
{
    $empresa = Empresa::factory()->enProduccion()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 2]);
    $puntoVenta = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 5]);

    return Factura::factory()->create([
        'empresa_id' => $empresa->id,
        'punto_venta_id' => $puntoVenta->id,
        'estado' => Factura::ESTADO_PENDIENTE,
        'ruta_pdf' => 'siat/pdf/ya-existe.pdf',
        'xml_firmado' => '<factura/>',
    ]);
}

test('un fallo puntual del SIAT reintenta en vez de abrir contingencia', function () {
    $factura = facturaParaEnviar();

    $servicio = Mockery::mock(ServicioFacturacion::class);
    $servicio->shouldReceive('recepcionarFactura')->andThrow(new SiatException('sin respuesta'));
    fabricaQueDevuelve($servicio);

    $job = (new EnviarFacturaAlSiat($factura->id))->withFakeQueueInteractions();
    $job->job->attempts = 1;

    $job->handle(app(GeneradorPdf::class), app(GestorContingencia::class), app(FabricaServicios::class));

    $job->assertReleased();

    // Nada de contingencia todavia: un hipo de red no es una caida del SIN.
    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_PENDIENTE);
    expect(EventoSignificativo::count())->toBe(0);
});

test('agotados los reintentos la factura pasa a contingencia', function () {
    $factura = facturaParaEnviar();

    $servicio = Mockery::mock(ServicioFacturacion::class);
    $servicio->shouldReceive('recepcionarFactura')->andThrow(new SiatException('sin respuesta'));
    fabricaQueDevuelve($servicio);

    $job = (new EnviarFacturaAlSiat($factura->id))->withFakeQueueInteractions();
    $job->job->attempts = $job->tries;

    $job->handle(app(GeneradorPdf::class), app(GestorContingencia::class), app(FabricaServicios::class));

    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_CONTINGENCIA);
    expect($factura->fresh()->tipo_emision)->toBe(Factura::EMISION_CONTINGENCIA);
    expect(EventoSignificativo::where('estado', 'ABIERTO')->count())->toBe(1);
});

test('un envio exitoso guarda el codigo de recepcion y encola la verificacion', function () {
    Queue::fake();
    $factura = facturaParaEnviar();

    $servicio = Mockery::mock(ServicioFacturacion::class);
    $servicio->shouldReceive('recepcionarFactura')->andReturn([
        'RespuestaServicioFacturacion' => ['codigoRecepcion' => '1234567890'],
    ]);
    fabricaQueDevuelve($servicio);

    (new EnviarFacturaAlSiat($factura->id))->handle(
        app(GeneradorPdf::class),
        app(GestorContingencia::class),
        app(FabricaServicios::class),
    );

    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_ENVIADA)
        ->and($factura->fresh()->codigo_recepcion)->toBe('1234567890');

    Queue::assertPushed(VerificarEstadoFactura::class);
});

test('el paquete viaja con los codigos del SIN y no con ids internos', function () {
    $factura = facturaParaEnviar();
    $factura->update(['estado' => Factura::ESTADO_CONTINGENCIA]);
    $puntoVenta = $factura->puntoVenta;

    $paquete = Paquete::create([
        'empresa_id' => $factura->empresa_id,
        'punto_venta_id' => $puntoVenta->id,
        'cantidad_facturas' => 1,
        'estado' => 'PENDIENTE',
    ]);
    $factura->update(['paquete_id' => $paquete->id]);

    // Otra factura en contingencia del mismo punto de venta, fuera del paquete.
    $ajena = Factura::factory()->create([
        'empresa_id' => $factura->empresa_id,
        'punto_venta_id' => $puntoVenta->id,
        'estado' => Factura::ESTADO_CONTINGENCIA,
        'xml_firmado' => '<factura/>',
    ]);

    $enviados = [];
    $servicio = Mockery::mock(ServicioFacturacion::class);
    $servicio->shouldReceive('recepcionarPaquete')
        ->andReturnUsing(function (array $datos) use (&$enviados) {
            $enviados = $datos;

            return ['RespuestaServicioFacturacion' => ['codigoRecepcion' => '999']];
        });
    fabricaQueDevuelve($servicio);

    (new EnviarPaqueteContingencia($paquete->id))->handle(
        app(ArmadorPaquete::class),
        app(FabricaServicios::class),
    );

    // Antes se mandaba $paquete->punto_venta_id, que es la PK de la tabla.
    expect($enviados['codigoPuntoVenta'])->toBe($puntoVenta->codigo_punto_venta)
        ->and($enviados['codigoSucursal'])->toBe($puntoVenta->sucursal->codigo_sucursal);

    expect($paquete->fresh()->estado)->toBe('ENVIADO');
    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_ENVIADA);

    // La que no iba en el paquete sigue esperando su turno.
    expect($ajena->fresh()->estado)->toBe(Factura::ESTADO_CONTINGENCIA);
});
