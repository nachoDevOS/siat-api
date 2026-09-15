<?php

use App\Exceptions\FacturaInvalidaException;
use App\Jobs\EnviarFacturaAlSiat;
use App\Models\Certificado;
use App\Models\Cufd;
use App\Models\Empresa;
use App\Models\EventoSignificativo;
use App\Models\Factura;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Services\Contingencia\GestorContingencia;
use App\Services\Factura\EmisorFactura;
use App\Services\Factura\GeneradorCuf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| El tipo de emision tiene que decidirse ANTES del CUF
|--------------------------------------------------------------------------
|
| tipo_emision es uno de los nueve campos que se concatenan para calcular el
| CUF. Antes se calculaba siempre con 1 (en linea) y, si despues el envio
| fallaba, GestorContingencia::derivar() cambiaba la columna a 2. Quedaba una
| factura declarando 'codigoEmision = 2' con un CUF que codifica 1.
|
| El SIN revalida el CUF contra los campos, asi que rechazaba el paquete
| entero. En local no daba error: la factura se emitia, se firmaba y se
| guardaba igual.
|
*/

/**
 * @return array{0: Empresa, 1: PuntoVenta}
 */
function empresaQuePuedeEmitir(): array
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
function ventaSimple(string $referencia): array
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
            'codigo_producto_sin' => 99100,
            'descripcion' => 'Tornillo autoperforante',
            'cantidad' => 2,
            'unidad_medida' => 57,
            'precio_unitario' => 10,
        ]],
    ];
}

function abrirEventoDeContingencia(PuntoVenta $puntoVenta): EventoSignificativo
{
    return EventoSignificativo::create([
        'empresa_id' => $puntoVenta->sucursal->empresa_id,
        'punto_venta_id' => $puntoVenta->id,
        'codigo_evento' => 1,
        'descripcion' => 'Corte de conexion con el SIAT',
        'fecha_inicio' => now(),
        'estado' => 'ABIERTO',
    ]);
}

// ---- El campo cambia el CUF -------------------------------------------------

test('cambiar el tipo de emision cambia el CUF', function () {
    // La razon de ser de todo lo demas: si estos dos CUF fueran iguales, dejar
    // el campo desalineado no tendria consecuencia.
    $campos = [
        'nit' => 1234567890,
        'fecha' => '20260914120000123',
        'sucursal' => 0,
        'modalidad' => 1,
        'tipo_factura' => 1,
        'tipo_documento_sector' => 1,
        'numero_factura' => 7,
        'punto_venta' => 0,
    ];

    $generador = app(GeneradorCuf::class);

    $enLinea = $generador->generar($campos + ['tipo_emision' => Factura::EMISION_EN_LINEA], 'ABC123');
    $contingencia = $generador->generar($campos + ['tipo_emision' => Factura::EMISION_CONTINGENCIA], 'ABC123');

    expect($enLinea)->not->toBe($contingencia);
});

// ---- Emision con el evento abierto -----------------------------------------

test('con contingencia abierta la factura nace fuera de linea', function () {
    Queue::fake();
    [$empresa, $puntoVenta] = empresaQuePuedeEmitir();
    abrirEventoDeContingencia($puntoVenta);

    $factura = app(EmisorFactura::class)->emitir($empresa, ventaSimple('VENTA-1'));

    // El CUF se calculo con 2, asi que la columna puede declarar 2 sin mentir.
    expect($factura->tipo_emision)->toBe(Factura::EMISION_CONTINGENCIA)
        ->and($factura->estado)->toBe(Factura::ESTADO_CONTINGENCIA);
});

test('con contingencia abierta no se intenta el envio individual', function () {
    Queue::fake();
    [$empresa, $puntoVenta] = empresaQuePuedeEmitir();
    abrirEventoDeContingencia($puntoVenta);

    app(EmisorFactura::class)->emitir($empresa, ventaSimple('VENTA-2'));

    // El SIAT no responde: estas facturas viajan juntas en el paquete.
    Queue::assertNotPushed(EnviarFacturaAlSiat::class);
});

test('sin contingencia la factura se emite en linea y se encola su envio', function () {
    Queue::fake();
    [$empresa] = empresaQuePuedeEmitir();

    $factura = app(EmisorFactura::class)->emitir($empresa, ventaSimple('VENTA-3'));

    expect($factura->tipo_emision)->toBe(Factura::EMISION_EN_LINEA)
        ->and($factura->estado)->toBe(Factura::ESTADO_PENDIENTE);

    Queue::assertPushed(EnviarFacturaAlSiat::class);
});

// ---- Derivar no reescribe el tipo de emision --------------------------------

test('derivar a contingencia no toca el tipo de emision de una factura ya emitida', function () {
    Queue::fake();
    [$empresa, $puntoVenta] = empresaQuePuedeEmitir();

    // Se emitio en linea con normalidad: su CUF codifica 1.
    $factura = app(EmisorFactura::class)->emitir($empresa, ventaSimple('VENTA-4'));
    $cufOriginal = $factura->cuf;

    // El envio falla y la factura se deriva a contingencia.
    app(GestorContingencia::class)->derivar($factura);

    $factura->refresh();

    // Cambia el estado, NO el tipo de emision: el CUF ya salio y ya se imprimio.
    expect($factura->estado)->toBe(Factura::ESTADO_CONTINGENCIA)
        ->and($factura->tipo_emision)->toBe(Factura::EMISION_EN_LINEA)
        ->and($factura->cuf)->toBe($cufOriginal);
});

test('la factura siguiente a una caida ya nace con el CUF de contingencia', function () {
    Queue::fake();
    [$empresa, $puntoVenta] = empresaQuePuedeEmitir();

    $primera = app(EmisorFactura::class)->emitir($empresa, ventaSimple('VENTA-5'));
    app(GestorContingencia::class)->derivar($primera);

    // La caja sigue vendiendo con el evento abierto.
    $segunda = app(EmisorFactura::class)->emitir($empresa, ventaSimple('VENTA-6'));

    expect($primera->fresh()->tipo_emision)->toBe(Factura::EMISION_EN_LINEA)
        ->and($segunda->tipo_emision)->toBe(Factura::EMISION_CONTINGENCIA);
});

/*
|--------------------------------------------------------------------------
| No se emite contra un punto de venta que el SIN no conoce
|--------------------------------------------------------------------------
|
| POST /api/v1/puntos-venta crea el registro LOCAL, pero no da de alta el punto
| de venta en el SIN: eso lo hace el paso 10 del piloto y es irreversible. El
| codigo de punto de venta entra al CUF, asi que emitir contra uno que el SIN
| desconoce produce facturas que rechaza en bloque. Antes solo se exigia
| 'activo' y estas pasaban.
|
*/

test('un punto de venta sin registrar en el SIAT no puede emitir', function () {
    Queue::fake();
    $empresa = Empresa::factory()->enProduccion()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    PuntoVenta::factory()->for($sucursal)->sinRegistrarEnSiat()->create(['codigo_punto_venta' => 0]);
    Certificado::factory()->for($empresa)->firmable()->create();

    expect(fn () => app(EmisorFactura::class)->emitir($empresa, ventaSimple('VENTA-7')))
        ->toThrow(FacturaInvalidaException::class);

    expect(Factura::count())->toBe(0);
});

test('el error dice exactamente que falta', function () {
    Queue::fake();
    $empresa = Empresa::factory()->enProduccion()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    PuntoVenta::factory()->for($sucursal)->sinRegistrarEnSiat()->create(['codigo_punto_venta' => 0]);
    Certificado::factory()->for($empresa)->firmable()->create();

    try {
        app(EmisorFactura::class)->emitir($empresa, ventaSimple('VENTA-8'));
        $this->fail('Se esperaba FacturaInvalidaException.');
    } catch (FacturaInvalidaException $e) {
        expect($e->errores[0])->toContain('no esta registrado en el SIAT');
    }
});

test('no se reserva numero de factura si el punto de venta no sirve', function () {
    // El correlativo se reserva con bloqueo de fila y no se puede devolver: un
    // corte tardio dejaria huecos en la numeracion ante el SIN.
    Queue::fake();
    $empresa = Empresa::factory()->enProduccion()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $pv = PuntoVenta::factory()->for($sucursal)->sinRegistrarEnSiat()->create(['codigo_punto_venta' => 0]);
    Certificado::factory()->for($empresa)->firmable()->create();

    expect(fn () => app(EmisorFactura::class)->emitir($empresa, ventaSimple('VENTA-9')))
        ->toThrow(FacturaInvalidaException::class);

    expect($pv->fresh()->siguiente_factura)->toBe(1);
});
