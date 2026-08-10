<?php

use App\Jobs\NotificarWebhook;
use App\Jobs\VerificarEstadoFactura;
use App\Models\Cufd;
use App\Models\Cuis;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\FacturaItem;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Services\Factura\ConstructorXml;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\ServicioFacturacion;
use App\Services\Siat\SiatClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Contrato con el SIAT
|--------------------------------------------------------------------------
|
| Fija los detalles del contrato que se verificaron contra un sistema que
| factura en produccion ante el SIN. Cada uno de estos puntos estaba mal antes
| y ninguno da error en local: la factura se emite igual y el SIN la rechaza.
| Por eso viven en un test propio y no mezclados con los demas.
|
*/

function facturaCompleta(): Factura
{
    $empresa = Empresa::factory()->enProduccion()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $puntoVenta = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);
    Cuis::factory()->for($puntoVenta)->create(['codigo' => 'CUIS-VIGENTE']);
    Cufd::factory()->for($puntoVenta)->create();

    $factura = Factura::factory()->create([
        'empresa_id' => $empresa->id,
        'punto_venta_id' => $puntoVenta->id,
        'estado' => Factura::ESTADO_PENDIENTE,
        'ruta_pdf' => 'siat/pdf/ya-existe.pdf',
        // Campos opcionales vacios: son los que deben viajar como xsi:nil.
        'comprador_complemento' => null,
        'numero_tarjeta' => null,
    ]);

    FacturaItem::factory()->for($factura)->create(['numero_serie' => null, 'numero_imei' => null]);

    return $factura->fresh();
}

// ---- XML -------------------------------------------------------------------

test('el XML declara el esquema que el SIN usa para validarlo', function () {
    $xml = app(ConstructorXml::class)->construir(facturaCompleta());

    expect($xml)->toContain('<facturaElectronicaCompraVenta')
        ->and($xml)->toContain('xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"')
        ->and($xml)->toContain('xsi:noNamespaceSchemaLocation="facturaElectronicaCompraVenta.xsd"');
});

test('los campos vacios viajan como xsi:nil y no se omiten', function () {
    $xml = app(ConstructorXml::class)->construir(facturaCompleta());

    // El XSD del SIN declara una secuencia: omitir un elemento corre a todos
    // los demas de lugar y el documento deja de validar.
    expect($xml)->toContain('<complemento xsi:nil="true"/>')
        ->and($xml)->toContain('<numeroTarjeta xsi:nil="true"/>')
        ->and($xml)->toContain('<numeroSerie xsi:nil="true"/>');
});

test('un monto en cero viaja con su valor y no como nulo', function () {
    $factura = facturaCompleta();
    $factura->update(['gift_card' => 0]);

    $xml = app(ConstructorXml::class)->construir($factura->fresh());

    // El cero es un valor, no un vacio.
    expect($xml)->toContain('<montoGiftCard>0.00</montoGiftCard>');
});

test('la cabecera mantiene el orden exacto de campos del esquema', function () {
    $xml = app(ConstructorXml::class)->construir(facturaCompleta());

    $orden = ['nitEmisor', 'razonSocialEmisor', 'municipio', 'telefono', 'numeroFactura',
        'cuf', 'cufd', 'codigoSucursal', 'direccion', 'codigoPuntoVenta', 'fechaEmision'];

    $posiciones = array_map(fn (string $campo): int => strpos($xml, "<{$campo}"), $orden);
    $ordenadas = $posiciones;
    sort($ordenadas);

    expect($posiciones)->toBe($ordenadas);
});

// ---- Envio -----------------------------------------------------------------

/**
 * Servicio real con la invocacion SOAP interceptada: deja ver la solicitud
 * exacta que se le mandaria al SIN sin necesidad de un SIAT del otro lado.
 */
function servicioQueCapturaLaSolicitud(Factura $factura): ServicioFacturacion
{
    $cliente = new SiatClient($factura->empresa);

    return new class($factura->empresa, $cliente) extends ServicioFacturacion
    {
        /** @var array<string, mixed> */
        public array $ultimaSolicitud = [];

        protected function invocar(string $claveServicio, string $operacion, array $parametros): mixed
        {
            $this->ultimaSolicitud = reset($parametros);

            return null;
        }
    };
}

test('el hash que viaja es el del gzip, no el del XML plano', function () {
    Queue::fake();

    $factura = facturaCompleta();
    $factura->update(['xml_firmado' => '<facturaElectronicaCompraVenta/>']);

    // Se llama al metodo real y se intercepta la invocacion SOAP para leer la
    // solicitud tal cual quedo armada.
    $servicio = servicioQueCapturaLaSolicitud($factura);
    $servicio->recepcionarFactura($factura, 'CUFD-1', 'CUIS-1');
    $enviado = $servicio->ultimaSolicitud;

    expect($enviado['hashArchivo'])->toBe(hash('sha256', $enviado['archivo']))
        ->and($enviado['hashArchivo'])->not->toBe(hash('sha256', $factura->xml_firmado));
});

test('la recepcion de factura lleva el CUIS ademas del CUFD', function () {
    $factura = facturaCompleta();
    $factura->update(['xml_firmado' => '<facturaElectronicaCompraVenta/>']);

    $servicio = servicioQueCapturaLaSolicitud($factura);
    $servicio->recepcionarFactura($factura, 'CUFD-1', 'CUIS-1');

    expect($servicio->ultimaSolicitud['cuis'])->toBe('CUIS-1')
        ->and($servicio->ultimaSolicitud['cufd'])->toBe('CUFD-1')
        ->and($servicio->ultimaSolicitud['tipoFacturaDocumento'])->toBe(1);
});

// ---- Estados devueltos por el SIN -------------------------------------------

/**
 * Corre VerificarEstadoFactura con el codigo de estado que devuelve el SIN.
 */
function verificarConEstado(Factura $factura, string $codigo, string $descripcion = ''): VerificarEstadoFactura
{
    $servicio = Mockery::mock(ServicioFacturacion::class);
    $servicio->shouldReceive('verificarEstado')->andReturn([
        'RespuestaServicioFacturacion' => [
            'codigoEstado' => $codigo,
            'codigoDescripcion' => $descripcion,
        ],
    ]);

    test()->mock(FabricaServicios::class, function ($mock) use ($servicio) {
        $mock->shouldReceive('facturacion')->andReturn($servicio);
    });

    $job = (new VerificarEstadoFactura($factura->id))->withFakeQueueInteractions();
    $job->handle(app(FabricaServicios::class));

    return $job;
}

test('el codigo 908 marca la factura como validada', function () {
    Queue::fake();

    $factura = facturaCompleta();
    $factura->update(['estado' => Factura::ESTADO_ENVIADA]);

    verificarConEstado($factura, '908', 'VALIDADA');

    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_VALIDADA);
    Queue::assertPushed(NotificarWebhook::class);
});

test('el codigo 902 es PENDIENTE: no valida ni observa, vuelve a preguntar', function () {
    Queue::fake();

    $factura = facturaCompleta();
    $factura->update(['estado' => Factura::ESTADO_ENVIADA]);

    // Antes este codigo se daba por validado y se le confirmaba al cliente un
    // documento que el SIN todavia no habia aceptado.
    $job = verificarConEstado($factura, '902', 'PENDIENTE');

    $job->assertReleased();
    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_ENVIADA);
    expect($factura->fresh()->codigo_estado_siat)->toBe('902');
    Queue::assertNotPushed(NotificarWebhook::class);
});

test('un codigo desconocido deja la factura observada', function () {
    Queue::fake();

    $factura = facturaCompleta();
    $factura->update(['estado' => Factura::ESTADO_ENVIADA]);

    verificarConEstado($factura, '905', 'RECHAZADA');

    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_OBSERVADA);
});
