<?php

use App\Exceptions\FacturaInvalidaException;
use App\Models\Certificado;
use App\Models\Cufd;
use App\Models\Empresa;
use App\Models\LeyendaFactura;
use App\Models\ProductoServicio;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Services\Factura\ConstructorXml;
use App\Services\Factura\EmisorFactura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * El XSD del SIN exige <actividadEconomica> en cada detalle y <leyenda> en la
 * cabecera. Los dos viajaban siempre con xsi:nil porque no habia de donde
 * sacarlos: el SIN habria rechazado toda factura y en local no daba error.
 *
 * Se resuelven desde los catalogos sincronizados del NIT y quedan guardados en
 * la factura, que es un documento congelado.
 */

/**
 * @return array{0: Empresa, 1: PuntoVenta}
 */
function empresaFirmante(): array
{
    $empresa = Empresa::factory()->enProduccion()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $puntoVenta = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);
    Cufd::factory()->for($puntoVenta)->create();
    Certificado::factory()->for($empresa)->firmable()->create();

    return [$empresa, $puntoVenta];
}

/**
 * @return array<string, mixed>
 */
function ventaDeUnItem(string $referencia, int $codigoProducto = 99100): array
{
    return [
        'sucursal' => 0,
        'punto_venta' => 0,
        'referencia_externa' => $referencia,
        'comprador' => [
            'tipo_documento' => 1,
            'numero_documento' => '1023456',
            'razon_social' => 'JUAN PEREZ',
        ],
        'metodo_pago' => 1,
        'items' => [[
            'codigo_producto_sin' => $codigoProducto,
            'descripcion' => 'Tornillo autoperforante',
            'cantidad' => 2,
            'unidad_medida' => 57,
            'precio_unitario' => 10,
        ]],
    ];
}

test('la actividad economica y la leyenda salen del catalogo del NIT', function () {
    Queue::fake();
    [$empresa] = empresaFirmante();

    ProductoServicio::create([
        'empresa_id' => $empresa->id,
        'codigo_actividad' => '620100',
        'codigo_producto' => '99100',
        'descripcion' => 'Tornillos',
    ]);
    LeyendaFactura::create([
        'empresa_id' => $empresa->id,
        'codigo_actividad' => '620100',
        'descripcion_leyenda' => 'Ley N 453: Tienes derecho a recibir informacion.',
    ]);

    $factura = app(EmisorFactura::class)->emitir($empresa, ventaDeUnItem('VTA-ACT-1'));

    expect($factura->items->first()->codigo_actividad)->toBe('620100')
        ->and($factura->leyenda)->toContain('Ley N 453');
});

test('el XML lleva la actividad y la leyenda con valor, no con xsi:nil', function () {
    Queue::fake();
    [$empresa] = empresaFirmante();

    ProductoServicio::create([
        'empresa_id' => $empresa->id,
        'codigo_actividad' => '620100',
        'codigo_producto' => '99100',
        'descripcion' => 'Tornillos',
    ]);
    LeyendaFactura::create([
        'empresa_id' => $empresa->id,
        'codigo_actividad' => '620100',
        'descripcion_leyenda' => 'Ley N 453',
    ]);

    $factura = app(EmisorFactura::class)->emitir($empresa, ventaDeUnItem('VTA-ACT-2'));
    $xml = app(ConstructorXml::class)->construir($factura->fresh());

    expect($xml)->toContain('<actividadEconomica>620100</actividadEconomica>')
        ->and($xml)->toContain('<leyenda>Ley N 453</leyenda>')
        ->and($xml)->not->toContain('<actividadEconomica xsi:nil="true"/>');
});

test('la leyenda que manda el cliente le gana a la del catalogo', function () {
    Queue::fake();
    [$empresa] = empresaFirmante();

    ProductoServicio::create([
        'empresa_id' => $empresa->id,
        'codigo_actividad' => '620100',
        'codigo_producto' => '99100',
        'descripcion' => 'Tornillos',
    ]);
    LeyendaFactura::create([
        'empresa_id' => $empresa->id,
        'codigo_actividad' => '620100',
        'descripcion_leyenda' => 'La del catalogo',
    ]);

    $venta = ventaDeUnItem('VTA-ACT-3');
    $venta['leyenda'] = 'La que impone el cliente';

    $factura = app(EmisorFactura::class)->emitir($empresa, $venta);

    expect($factura->leyenda)->toBe('La que impone el cliente');
});

test('un producto no homologado corta la emision si el catalogo ya se sincronizo', function () {
    Queue::fake();
    [$empresa] = empresaFirmante();

    // Hay catalogo, pero este producto no esta en el: el SIN lo rechazaria.
    ProductoServicio::create([
        'empresa_id' => $empresa->id,
        'codigo_actividad' => '620100',
        'codigo_producto' => '11111',
        'descripcion' => 'Otro producto',
    ]);

    expect(fn () => app(EmisorFactura::class)->emitir($empresa, ventaDeUnItem('VTA-ACT-4', 99100)))
        ->toThrow(FacturaInvalidaException::class);
});

test('sin catalogo sincronizado todavia se puede emitir', function () {
    Queue::fake();
    [$empresa] = empresaFirmante();

    // Cliente recien dado de alta: bloquear aca dejaria el sistema inusable.
    $factura = app(EmisorFactura::class)->emitir($empresa, ventaDeUnItem('VTA-ACT-5'));

    expect($factura->items->first()->codigo_actividad)->toBeNull();
});

test('no se emite sin certificado activo: la modalidad electronica va firmada', function () {
    Queue::fake();

    $empresa = Empresa::factory()->enProduccion()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $puntoVenta = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);
    Cufd::factory()->for($puntoVenta)->create();

    expect(fn () => app(EmisorFactura::class)->emitir($empresa, ventaDeUnItem('VTA-SIN-CERT')))
        ->toThrow(FacturaInvalidaException::class);
});

test('la factura emitida queda firmada', function () {
    Queue::fake();
    [$empresa] = empresaFirmante();

    $factura = app(EmisorFactura::class)->emitir($empresa, ventaDeUnItem('VTA-FIRMADA'));

    expect($factura->fresh()->xml_firmado)
        ->toContain('<SignatureValue')
        ->toContain('<X509Certificate');
});
