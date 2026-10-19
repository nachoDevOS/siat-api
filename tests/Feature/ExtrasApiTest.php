<?php

use App\Jobs\EnviarFacturaAlSiat;
use App\Models\Certificado;
use App\Models\Cufd;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\FacturaItem;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function empresaExtras(): Empresa
{
    $empresa = Empresa::factory()->enProduccion()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $pv = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);
    Cufd::factory()->create(['punto_venta_id' => $pv->id]);
    Certificado::factory()->for($empresa)->firmable()->create();

    return $empresa;
}

/**
 * @return array<string, mixed>
 */
function ventaExtras(string $referencia, float $precio = 10): array
{
    return [
        'sucursal' => 0,
        'punto_venta' => 0,
        'referencia_externa' => $referencia,
        'comprador' => ['tipo_documento' => 1, 'numero_documento' => '1234567', 'razon_social' => 'JUAN PEREZ'],
        'metodo_pago' => 1,
        'items' => [['codigo_producto_sin' => 83141, 'descripcion' => 'X', 'cantidad' => 1, 'unidad_medida' => 58, 'precio_unitario' => $precio]],
    ];
}

function tokenExtras(Empresa $empresa, string $perfil = 'administracion'): string
{
    return $empresa->emitirTokenApi('Test', $perfil)->plainTextToken;
}

// --- Dashboard -----------------------------------------------------------------------

test('el dashboard resume lo facturado: mes, anio, estados, tendencia y notas aparte', function () {
    $this->travelTo(now()->setDate(2026, 10, 15));
    $empresa = empresaExtras();
    $pv = PuntoVenta::first();
    $crear = fn (array $a) => Factura::factory()->create(array_merge(['empresa_id' => $empresa->id, 'punto_venta_id' => $pv->id, 'estado' => Factura::ESTADO_VALIDADA], $a));

    $crear(['monto_total' => 100, 'fecha_emision' => now()]);
    $crear(['monto_total' => 50, 'fecha_emision' => now()->subMonth()]);
    $crear(['monto_total' => 999, 'fecha_emision' => now(), 'estado' => Factura::ESTADO_ANULADA]);
    $crear(['monto_total' => 30, 'fecha_emision' => now(), 'codigo_documento_sector' => 24]);
    // De otra empresa: no cuenta.
    Factura::factory()->create(['monto_total' => 7, 'fecha_emision' => now(), 'estado' => Factura::ESTADO_VALIDADA]);

    $this->withToken(tokenExtras($empresa, 'consulta'))->getJson('/api/v1/dashboard')
        ->assertSuccessful()
        ->assertJsonPath('ambiente', 'PRODUCCION')
        ->assertJsonPath('facturas.total', 3)
        ->assertJsonPath('facturas.mes', ['cantidad' => 1, 'monto' => 100])
        ->assertJsonPath('facturas.anio', ['cantidad' => 2, 'monto' => 150])
        ->assertJsonPath('facturas.por_estado', ['ANULADA' => 1, 'VALIDADA' => 2])
        ->assertJsonCount(6, 'facturas.tendencia')
        ->assertJsonPath('facturas.tendencia.5', ['mes' => '2026-10', 'cantidad' => 1, 'monto' => 100])
        ->assertJsonPath('facturas.tendencia.4', ['mes' => '2026-09', 'cantidad' => 1, 'monto' => 50])
        ->assertJsonPath('notas_credito_debito.mes', ['cantidad' => 1, 'monto_devuelto' => 30]);
});

// --- Mini ticket y ZIP ---------------------------------------------------------------

test('el mini ticket y el ZIP se descargan desde su enlace publico', function () {
    $factura = Factura::factory()->create(['xml_firmado' => '<facturaElectronicaCompraVenta/>']);
    FacturaItem::factory()->for($factura)->create();

    $this->get($factura->urlPublica('mini'))->assertSuccessful()->assertHeader('Content-Type', 'application/pdf');

    $respuesta = $this->get($factura->urlPublica('zip'))->assertSuccessful()->assertHeader('Content-Type', 'application/zip');

    $ruta = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($ruta, $respuesta->getContent());
    $zip = new ZipArchive;
    $zip->open($ruta);
    $nombres = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->sort()->values()->all();
    $xml = $zip->getFromName("factura-{$factura->numero_factura}.xml");
    $zip->close();
    unlink($ruta);

    expect($nombres)->toBe([
        "factura-{$factura->numero_factura}-rollo.pdf",
        "factura-{$factura->numero_factura}.pdf",
        "factura-{$factura->numero_factura}.xml",
    ])->and($xml)->toBe('<facturaElectronicaCompraVenta/>');

    // Sin firma no se abre.
    $this->get("/factura/{$factura->cuf}/zip")->assertNotFound();
});

// --- Lote ----------------------------------------------------------------------------

test('el lote emite cada venta por separado y una rechazada no frena a las demas', function () {
    Queue::fake();
    $empresa = empresaExtras();
    $mala = ventaExtras('V-2');
    $mala['punto_venta'] = 9;

    $respuesta = $this->withToken(tokenExtras($empresa, 'caja'))->postJson('/api/v1/facturas/lote', [
        'ventas' => [ventaExtras('V-1'), $mala, ventaExtras('V-3', 20)],
    ])->assertSuccessful()
        ->assertJsonPath('exito', false)
        ->assertJsonPath('emitidas', 2)
        ->assertJsonPath('rechazadas', 1)
        ->assertJsonPath('resultados.0.exito', true)
        ->assertJsonPath('resultados.1.error', 'PUNTO_VENTA_NO_ENCONTRADO')
        ->assertJsonPath('resultados.2.factura.total', 20);

    Queue::assertPushed(EnviarFacturaAlSiat::class, 2);

    // Reintentar el lote no duplica: vuelven las mismas por su referencia.
    $this->withToken(tokenExtras($empresa, 'caja'))->postJson('/api/v1/facturas/lote', [
        'ventas' => [ventaExtras('V-1'), ventaExtras('V-3', 20)],
    ])->assertJsonPath('resultados.0.factura.cuf', $respuesta->json('resultados.0.factura.cuf'));

    expect(Factura::count())->toBe(2);
});

test('el lote exige referencia por venta, sin repetir, y no mas de 20', function (array $ventas, string $campo) {
    $empresa = empresaExtras();

    $this->withToken(tokenExtras($empresa))->postJson('/api/v1/facturas/lote', ['ventas' => $ventas])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($campo);
})->with([
    'sin referencia' => [[array_diff_key(ventaExtras('X'), ['referencia_externa' => 1])], 'ventas.0.referencia_externa'],
    'repetida' => [[ventaExtras('X'), ventaExtras('X')], 'ventas.0.referencia_externa'],
    'mas de 20' => [array_map(fn ($i) => ventaExtras("V-{$i}"), range(1, 21)), 'ventas'],
]);
