<?php

use App\Models\Empresa;
use App\Models\EventoSignificativo;
use App\Models\Factura;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Services\Contingencia\ArmadorPaquete;
use App\Services\Contingencia\GestorContingencia;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Punto de venta de una empresa en produccion, listo para contingencia.
 */
function puntoVentaContingencia(): PuntoVenta
{
    $empresa = Empresa::factory()->enProduccion()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 7]);

    return PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 3]);
}

function facturaEnContingencia(PuntoVenta $puntoVenta, int $numero): Factura
{
    return Factura::factory()->create([
        'empresa_id' => $puntoVenta->sucursal->empresa_id,
        'punto_venta_id' => $puntoVenta->id,
        'estado' => Factura::ESTADO_CONTINGENCIA,
        'tipo_emision' => Factura::EMISION_CONTINGENCIA,
        'numero_factura' => $numero,
        'xml_firmado' => '<?xml version="1.0" encoding="UTF-8"?><factura>'.$numero.'</factura>',
    ]);
}

test('derivar abre un solo evento significativo para toda la racha', function () {
    $puntoVenta = puntoVentaContingencia();
    $gestor = app(GestorContingencia::class);

    $primera = facturaEnContingencia($puntoVenta, 1);
    $segunda = facturaEnContingencia($puntoVenta, 2);

    $evento = $gestor->derivar($primera);
    $mismoEvento = $gestor->derivar($segunda);

    expect($mismoEvento->id)->toBe($evento->id);
    expect(EventoSignificativo::count())->toBe(1);
    expect($primera->fresh()->tipo_emision)->toBe(Factura::EMISION_CONTINGENCIA);
});

test('recuperar congela el conjunto de facturas del paquete', function () {
    $puntoVenta = puntoVentaContingencia();
    $gestor = app(GestorContingencia::class);

    $dentro = facturaEnContingencia($puntoVenta, 1);
    $evento = $gestor->derivar($dentro);

    $paquete = $gestor->recuperar($evento->fresh());

    // Esta entra a contingencia DESPUES de armado el paquete.
    $fuera = facturaEnContingencia($puntoVenta, 2);

    expect($paquete)->not->toBeNull();
    expect($paquete->cantidad_facturas)->toBe(1);
    expect($dentro->fresh()->paquete_id)->toBe($paquete->id);
    expect($fuera->fresh()->paquete_id)->toBeNull();
});

test('el armador solo mete en el paquete las facturas asignadas', function () {
    $puntoVenta = puntoVentaContingencia();
    $gestor = app(GestorContingencia::class);

    $dentro = facturaEnContingencia($puntoVenta, 1);
    $paquete = $gestor->recuperar($gestor->derivar($dentro));

    $fuera = facturaEnContingencia($puntoVenta, 2);

    $xml = app(ArmadorPaquete::class)->armar($paquete);

    expect($xml)->toContain('<factura>1</factura>')
        ->and($xml)->not->toContain('<factura>2</factura>');

    // La que quedo fuera sigue pendiente, no se da por enviada.
    expect($fuera->fresh()->estado)->toBe(Factura::ESTADO_CONTINGENCIA);
});

test('recuperar sin facturas cierra el evento y no crea paquete', function () {
    $puntoVenta = puntoVentaContingencia();
    $gestor = app(GestorContingencia::class);

    $factura = facturaEnContingencia($puntoVenta, 1);
    $evento = $gestor->derivar($factura);

    // La factura se transmite por otra via antes de recuperar.
    $factura->update(['estado' => Factura::ESTADO_ENVIADA]);

    expect($gestor->recuperar($evento->fresh()))->toBeNull();
    expect($evento->fresh()->estado)->toBe('CERRADO');
});
