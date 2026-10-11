<?php

use App\Exceptions\SiatException;
use App\Http\Requests\EmitirFacturaRequest;
use App\Jobs\AnularFacturaEnSiat;
use App\Jobs\EnviarFacturaAlSiat;
use App\Models\Certificado;
use App\Models\Cufd;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\FacturaAnulada;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Services\Factura\CalculadorTotales;
use App\Services\Factura\EmisorFactura;
use App\Services\Factura\GeneradorPdf;
use App\Services\Factura\ValidadorFactura;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\ServicioFacturacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

/**
 * Valida una venta minima con las reglas del FormRequest de emision.
 *
 * @param  array<string, mixed>  $extra
 */
function validarVenta(array $extra = []): Illuminate\Validation\Validator
{
    $request = new EmitirFacturaRequest;

    return Validator::make(array_merge([
        'sucursal' => 0,
        'punto_venta' => 0,
        'comprador' => [
            'tipo_documento' => 1,
            'numero_documento' => '1234567',
            'razon_social' => 'PEREZ',
        ],
        'metodo_pago' => 1,
        'items' => [[
            'codigo_producto_sin' => 99100,
            'descripcion' => 'Producto',
            'cantidad' => 1,
            'unidad_medida' => 58,
            'precio_unitario' => 100,
        ]],
    ], $extra), $request->rules(), $request->messages());
}

test('el sujeto a IVA es monto total menos gift card, como valida el SIN en el sector 1', function () {
    $totales = app(CalculadorTotales::class)->calcular(
        [['cantidad' => 2, 'precio_unitario' => 50, 'descuento' => 10]],
        descuentoGlobal: 5,
        giftCard: 20,
    );

    expect($totales['monto_total'])->toBe(85.0)
        ->and($totales['monto_total_sujeto_iva'])->toBe(65.0)
        ->and($totales)->not->toHaveKey('anticipo');
});

test('la API rechaza un anticipo porque compra-venta no lo admite', function () {
    $validador = validarVenta(['anticipo' => 50]);

    expect($validador->fails())->toBeTrue()
        ->and($validador->errors()->first('anticipo'))
        ->toBe('La factura de compra-venta (sector 1) no admite anticipo.');
});

test('un anticipo en cero o ausente no molesta', function (array $extra) {
    expect(validarVenta($extra)->passes())->toBeTrue();
})->with([
    'ausente' => [[]],
    'nulo' => [['anticipo' => null]],
    'cero' => [['anticipo' => 0]],
]);

test('el envio en linea no toca una factura que ya no esta PENDIENTE', function (string $estado) {
    $factura = Factura::factory()->create(['estado' => $estado]);

    $this->mock(FabricaServicios::class, fn ($mock) => $mock->shouldNotReceive('facturacion'));

    app()->call([new EnviarFacturaAlSiat($factura->id), 'handle']);

    expect($factura->fresh()->estado)->toBe($estado)
        ->and($factura->fresh()->ruta_pdf)->toBeNull();
})->with([
    Factura::ESTADO_RECIBIDA,
    Factura::ESTADO_OBSERVADA,
    Factura::ESTADO_CONTINGENCIA,
    Factura::ESTADO_ANULADA,
    Factura::ESTADO_VALIDADA,
    Factura::ESTADO_ENVIADA,
]);

test('el validador corta descuentos y gift card que dejan montos negativos', function (array $venta, string $error) {
    $errores = app(ValidadorFactura::class)->validar(array_merge([
        'comprador' => ['razon_social' => 'PEREZ', 'numero_documento' => '1234567'],
        'items' => [['descripcion' => 'X', 'cantidad' => 2, 'precio_unitario' => 50, 'descuento' => 0]],
    ], $venta));

    expect(implode(' ', $errores))->toContain($error);
})->with([
    'descuento de linea mayor al importe' => [
        ['items' => [['descripcion' => 'X', 'cantidad' => 1, 'precio_unitario' => 10, 'descuento' => 15]]],
        'supera el importe de la linea',
    ],
    'descuento global mayor al total' => [['descuento_global' => 150], 'monto total quedaria negativo'],
    'gift card mayor al total' => [['gift_card' => 101], 'gift card supera el monto total'],
]);

test('una venta con montos coherentes pasa el validador', function () {
    $errores = app(ValidadorFactura::class)->validar([
        'comprador' => ['razon_social' => 'PEREZ', 'numero_documento' => '1234567'],
        'descuento_global' => 10,
        'gift_card' => 90,
        'items' => [['descripcion' => 'X', 'cantidad' => 2, 'precio_unitario' => 50, 'descuento' => 0]],
    ]);

    expect($errores)->toBe([]);
});

test('el numero de tarjeta se guarda y se firma enmascarado', function (string $recibido, string $guardado) {
    Queue::fake();
    $empresa = Empresa::factory()->enProduccion()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $pv = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);
    Cufd::factory()->create(['punto_venta_id' => $pv->id]);
    Certificado::factory()->for($empresa)->firmable()->create();

    $factura = app(EmisorFactura::class)->emitir($empresa, [
        'sucursal' => 0,
        'punto_venta' => 0,
        'comprador' => ['tipo_documento' => 1, 'numero_documento' => '1234567', 'razon_social' => 'JUAN PEREZ'],
        'metodo_pago' => 2,
        'numero_tarjeta' => $recibido,
        'items' => [['codigo_producto_sin' => 83141, 'descripcion' => 'X', 'cantidad' => 1, 'unidad_medida' => 58, 'precio_unitario' => 10]],
    ]);

    expect($factura->numero_tarjeta)->toBe($guardado)
        ->and($factura->xml_firmado)->toContain("<numeroTarjeta>{$guardado}</numeroTarjeta>")
        ->and($factura->xml_firmado)->not->toContain('12345678');
})->with([
    'completa' => ['4797123456787896', '4797000000007896'],
    'con espacios' => ['4797 1234 5678 7896', '4797000000007896'],
    'ya enmascarada' => ['4797000000007896', '4797000000007896'],
]);

test('si el PDF falla la factura igual viaja al SIN', function () {
    Queue::fake();
    $factura = Factura::factory()->create(['xml_firmado' => '<f/>']);

    $this->mock(GeneradorPdf::class, fn ($mock) => $mock->shouldReceive('generarYGuardar')->andThrow(new RuntimeException('dompdf')));

    $facturacion = Mockery::mock(ServicioFacturacion::class);
    $facturacion->shouldReceive('recepcionarFactura')->once()->andReturn((object) [
        'RespuestaServicioFacturacion' => (object) ['transaccion' => true, 'codigoRecepcion' => 'REC-1', 'codigoEstado' => 901],
    ]);
    $this->mock(FabricaServicios::class, fn ($mock) => $mock->shouldReceive('facturacion')->andReturn($facturacion));

    app()->call([new EnviarFacturaAlSiat($factura->id), 'handle']);

    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_RECIBIDA)
        ->and($factura->fresh()->ruta_pdf)->toBeNull();
});

test('agotados los intentos la anulacion queda pendiente sin ir a failed_jobs', function () {
    $factura = Factura::factory()->create(['estado' => Factura::ESTADO_ANULADA]);
    FacturaAnulada::create([
        'factura_id' => $factura->id,
        'motivo' => 1,
        'anulada_en' => now(),
        'estado' => FacturaAnulada::ESTADO_PENDIENTE,
        'estado_anterior' => Factura::ESTADO_VALIDADA,
    ]);

    $facturacion = Mockery::mock(ServicioFacturacion::class);
    $facturacion->shouldReceive('anular')->andThrow(new SiatException('SIAT caido'));
    $this->mock(FabricaServicios::class, fn ($mock) => $mock->shouldReceive('facturacion')->andReturn($facturacion));

    $job = Mockery::mock(AnularFacturaEnSiat::class.'[attempts,release]', [$factura->id]);
    $job->shouldReceive('attempts')->andReturn(5);
    $job->shouldNotReceive('release');

    app()->call([$job, 'handle']);

    expect($factura->anulacion->fresh()->estado)->toBe(FacturaAnulada::ESTADO_PENDIENTE)
        ->and($factura->fresh()->estado)->toBe(Factura::ESTADO_ANULADA);
});

test('la tarea programada retoma solo las anulaciones colgadas', function () {
    Queue::fake();

    $colgada = Factura::factory()->create(['estado' => Factura::ESTADO_ANULADA]);
    FacturaAnulada::create(['factura_id' => $colgada->id, 'motivo' => 1, 'anulada_en' => now()->subHour(), 'estado' => FacturaAnulada::ESTADO_PENDIENTE]);

    $reciente = Factura::factory()->create(['estado' => Factura::ESTADO_ANULADA]);
    FacturaAnulada::create(['factura_id' => $reciente->id, 'motivo' => 1, 'anulada_en' => now(), 'estado' => FacturaAnulada::ESTADO_PENDIENTE]);

    $confirmada = Factura::factory()->create(['estado' => Factura::ESTADO_ANULADA]);
    FacturaAnulada::create(['factura_id' => $confirmada->id, 'motivo' => 1, 'anulada_en' => now()->subHour(), 'estado' => FacturaAnulada::ESTADO_CONFIRMADA, 'codigo_recepcion' => 'X']);

    $this->artisan('schedule:test', ['--name' => 'reintentar-anulaciones'])->assertSuccessful();

    Queue::assertPushed(AnularFacturaEnSiat::class, 1);
    Queue::assertPushed(AnularFacturaEnSiat::class, fn ($job) => $job->facturaId === $colgada->id);
});

test('la descarga del PDF lo genera a pedido si el envio no lo dejo hecho', function () {
    $empresa = Empresa::factory()->create(['api_key_hash' => hash('sha256', 'clave-de-prueba')]);
    $factura = Factura::factory()->for($empresa)->create(['xml_firmado' => '<f/>', 'ruta_pdf' => null]);

    $this->mock(GeneradorPdf::class, function ($mock) {
        $mock->shouldReceive('generarYGuardar')->once()->andReturnUsing(function (Factura $f) {
            Storage::disk('local')->put("siat/pdf/{$f->cuf}.pdf", '%PDF-prueba');

            return "siat/pdf/{$f->cuf}.pdf";
        });
    });

    Storage::fake('local');

    $this->withHeader('X-Api-Key', 'clave-de-prueba')
        ->get("/api/v1/facturas/{$factura->cuf}/pdf")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($factura->fresh()->ruta_pdf)->toBe("siat/pdf/{$factura->cuf}.pdf");
});
