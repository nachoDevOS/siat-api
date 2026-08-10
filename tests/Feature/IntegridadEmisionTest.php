<?php

use App\Models\Certificado;
use App\Models\Cufd;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Services\Factura\EmisorFactura;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Venta minima valida. Se define aca y no se reutiliza la de ApiFacturaTest
 * para que este archivo no dependa del orden en que Pest carga los tests.
 *
 * @return array<string, mixed>
 */
function ventaMinima(string $referencia): array
{
    return [
        'sucursal' => 0,
        'punto_venta' => 0,
        'referencia_externa' => $referencia,
        'comprador' => [
            'tipo_documento' => 1,
            'numero_documento' => '1023456',
            'razon_social' => 'JUAN PEREZ',
            'email' => 'juan@correo.com',
        ],
        'metodo_pago' => 1,
        'usuario' => 'caja-01',
        'items' => [[
            'codigo_producto_sin' => 99100,
            'codigo_interno' => 'TOR-14',
            'descripcion' => 'Tornillo autoperforante',
            'cantidad' => 100,
            'unidad_medida' => 57,
            'precio_unitario' => 1.5,
        ]],
    ];
}

test('la base rechaza dos facturas con el mismo CUF', function () {
    $primera = Factura::factory()->create(['cuf' => 'CUF-REPETIDO-0001']);

    // La unicidad se comprueba contra la base y no contra un chequeo en PHP:
    // es lo unico que sobrevive a dos peticiones concurrentes.
    expect(fn () => Factura::factory()->create([
        'empresa_id' => $primera->empresa_id,
        'cuf' => 'CUF-REPETIDO-0001',
    ]))->toThrow(QueryException::class);
});

test('no se emite con un punto de venta dado de baja', function () {
    Queue::fake();

    $empresa = Empresa::factory()->enProduccion()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $puntoVenta = PuntoVenta::factory()->for($sucursal)->create([
        'codigo_punto_venta' => 0,
        'activo' => false,
    ]);
    Cufd::factory()->for($puntoVenta)->create();

    expect(fn () => app(EmisorFactura::class)->emitir($empresa, ventaMinima('VTA-PV-BAJA')))
        ->toThrow(ModelNotFoundException::class);

    expect(Factura::count())->toBe(0);
});

test('se emite con normalidad si el punto de venta sigue activo', function () {
    Queue::fake();

    $empresa = Empresa::factory()->enProduccion()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $puntoVenta = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);
    Cufd::factory()->for($puntoVenta)->create();
    Certificado::factory()->for($empresa)->firmable()->create();

    $factura = app(EmisorFactura::class)->emitir($empresa, ventaMinima('VTA-PV-ACTIVO'));

    expect($factura->punto_venta_id)->toBe($puntoVenta->id);
});
