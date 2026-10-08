<?php

use App\Jobs\EnviarPaqueteContingencia;
use App\Jobs\VerificarEstadoFactura;
use App\Models\Cufd;
use App\Models\Cuis;
use App\Models\EventoSignificativo;
use App\Models\Factura;
use App\Models\Paquete;
use App\Models\PuntoVenta;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\ServicioCodigos;
use App\Services\Siat\ServicioFacturacion;
use App\Services\Siat\ServicioOperaciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Punto de venta con CUIS y CUFD vigentes, y un evento abierto cuyo CUFD es
 * uno VIEJO (el que estaba en uso cuando se cayo el SIAT).
 *
 * @return array{0: PuntoVenta, 1: EventoSignificativo}
 */
function puntoVentaEnContingencia(): array
{
    $pv = PuntoVenta::factory()->create();
    Cuis::factory()->create(['punto_venta_id' => $pv->id, 'codigo' => 'CUIS-1']);
    Cufd::factory()->create(['punto_venta_id' => $pv->id, 'codigo' => 'CUFD-VIEJO']);
    Cufd::factory()->create(['punto_venta_id' => $pv->id, 'codigo' => 'CUFD-NUEVO']);

    $evento = EventoSignificativo::create([
        'empresa_id' => $pv->sucursal->empresa_id,
        'punto_venta_id' => $pv->id,
        'codigo_evento' => 1,
        'descripcion' => 'Corte de conexion con el SIAT',
        'cufd_evento' => 'CUFD-VIEJO',
        'fecha_inicio' => now()->subHour(),
        'estado' => 'ABIERTO',
    ]);

    return [$pv, $evento];
}

test('recuperar contingencia registra el evento con cuis, CUFD vigente y CUFD del evento', function () {
    Queue::fake();
    [$pv, $evento] = puntoVentaEnContingencia();

    $codigos = Mockery::mock(ServicioCodigos::class);
    $codigos->shouldReceive('verificarComunicacion')->andReturn((object) []);

    $operaciones = Mockery::mock(ServicioOperaciones::class);
    $operaciones->shouldReceive('registrarEvento')->once()
        ->with(Mockery::on(fn (array $d) => $d['cuis'] === 'CUIS-1'
            && $d['cufd'] === 'CUFD-NUEVO'
            && $d['cufdEvento'] === 'CUFD-VIEJO'))
        ->andReturn((object) ['RespuestaListaEventos' => (object) [
            'codigoRecepcionEventoSignificativo' => 9946710,
            'transaccion' => true,
        ]]);

    $this->mock(FabricaServicios::class, function ($mock) use ($codigos, $operaciones) {
        $mock->shouldReceive('codigos')->andReturn($codigos);
        $mock->shouldReceive('operaciones')->andReturn($operaciones);
    });

    $this->artisan('siat:recuperar-contingencia')->assertSuccessful();

    // Se lee bajo RespuestaListaEventos: antes todo evento parecia rechazado.
    expect($evento->fresh()->codigo_recepcion)->toBe('9946710')
        ->and($evento->fresh()->estado)->toBe('CERRADO');
});

test('el paquete de produccion viaja con todos los campos del WSDL', function () {
    [$pv, $evento] = puntoVentaEnContingencia();
    $evento->update(['codigo_recepcion' => '9946710', 'estado' => 'CERRADO']);
    $paquete = Paquete::create(['empresa_id' => $evento->empresa_id, 'punto_venta_id' => $pv->id,
        'evento_id' => $evento->id, 'cantidad_facturas' => 1, 'estado' => 'PENDIENTE']);
    Factura::factory()->create(['empresa_id' => $evento->empresa_id, 'punto_venta_id' => $pv->id,
        'paquete_id' => $paquete->id, 'estado' => Factura::ESTADO_CONTINGENCIA, 'xml_firmado' => '<f/>']);

    $facturacion = Mockery::mock(ServicioFacturacion::class);
    $facturacion->shouldReceive('recepcionarPaquete')->once()
        ->with(Mockery::on(fn (array $d) => $d['codigoEvento'] === '9946710'
            && $d['cantidadFacturas'] === 1
            && $d['codigoEmision'] === 2
            && $d['cuis'] === 'CUIS-1'
            && $d['cufd'] === 'CUFD-NUEVO'
            && array_key_exists('cafc', $d)), Mockery::type('string'))
        ->andReturn((object) ['RespuestaServicioFacturacion' => (object) [
            'transaccion' => true, 'codigoEstado' => 901, 'codigoRecepcion' => 'PAQ-1',
        ]]);
    $this->mock(FabricaServicios::class, fn ($mock) => $mock->shouldReceive('facturacion')->andReturn($facturacion));

    app()->call([new EnviarPaqueteContingencia($paquete->id), 'handle']);

    expect($paquete->fresh()->estado)->toBe('ENVIADO');
});

test('una verificacion en cola no pisa una factura que se anulo mientras esperaba', function () {
    $factura = Factura::factory()->create(['estado' => Factura::ESTADO_ANULADA]);

    $this->mock(FabricaServicios::class, fn ($mock) => $mock->shouldNotReceive('facturacion'));

    app()->call([new VerificarEstadoFactura($factura->id), 'handle']);

    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_ANULADA);
});
