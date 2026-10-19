<?php

use App\Jobs\EnviarFacturaAlSiat;
use App\Jobs\EnviarPaqueteMasivo;
use App\Jobs\NotificarWebhook;
use App\Jobs\ValidarPaqueteMasivo;
use App\Models\Certificado;
use App\Models\Cufd;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\LoteMasivo;
use App\Models\Paquete;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Services\Factura\EmisorFactura;
use App\Services\Factura\GestorMasiva;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\ServicioFacturacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function pvMasivo(): PuntoVenta
{
    $empresa = Empresa::factory()->enProduccion()->create();
    $pv = PuntoVenta::factory()->for(Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]))->create(['codigo_punto_venta' => 0]);
    Cufd::factory()->create(['punto_venta_id' => $pv->id]);
    Certificado::factory()->for($empresa)->firmable()->create();

    return $pv;
}

function emitirMasiva(PuntoVenta $pv): Factura
{
    return app(EmisorFactura::class)->emitir($pv->sucursal->empresa, [
        'sucursal' => 0,
        'punto_venta' => 0,
        'comprador' => ['tipo_documento' => 1, 'numero_documento' => '1234567', 'razon_social' => 'JUAN PEREZ'],
        'metodo_pago' => 1,
        'items' => [['codigo_producto_sin' => 83141, 'descripcion' => 'X', 'cantidad' => 1, 'unidad_medida' => 58, 'precio_unitario' => 10]],
    ]);
}

/**
 * ServicioFacturacion simulado con las respuestas de recepcion y validacion.
 */
function sinMasivo(?object $recepcion = null, ?object $validacion = null): MockInterface
{
    $servicio = Mockery::mock(ServicioFacturacion::class);

    if ($recepcion !== null) {
        $servicio->shouldReceive('recepcionarMasiva')->andReturn($recepcion);
    }

    if ($validacion !== null) {
        $servicio->shouldReceive('validarRecepcionMasiva')->andReturn($validacion);
    }

    test()->mock(FabricaServicios::class, fn ($m) => $m->shouldReceive('facturacion')->andReturn($servicio));

    return $servicio;
}

function respuestaMasiva(array $campos): object
{
    return (object) ['RespuestaServicioFacturacion' => (object) $campos];
}

test('con el lote abierto la factura nace MASIVA, con emision 3 en el CUF, y no se envia sola', function () {
    Queue::fake();
    $pv = pvMasivo();
    $lote = app(GestorMasiva::class)->abrir($pv);

    $factura = emitirMasiva($pv);

    expect($factura->estado)->toBe(Factura::ESTADO_MASIVA)
        ->and($factura->tipo_emision)->toBe(Factura::EMISION_MASIVA)
        ->and($factura->lote_masivo_id)->toBe($lote->id);
    Queue::assertNotPushed(EnviarFacturaAlSiat::class);

    // Abrir de nuevo devuelve el mismo lote.
    expect(app(GestorMasiva::class)->abrir($pv)->id)->toBe($lote->id);
});

test('cerrar arma los paquetes del lote y lo que se emite despues sale en linea', function () {
    Queue::fake();
    $pv = pvMasivo();
    app(GestorMasiva::class)->abrir($pv);
    $facturas = collect(range(1, 3))->map(fn () => emitirMasiva($pv));

    $lote = app(GestorMasiva::class)->cerrar($pv);

    expect($lote->estado)->toBe(LoteMasivo::ESTADO_CERRADO)
        ->and($lote->paquetes)->toHaveCount(1)
        ->and($lote->paquetes[0]->tipo)->toBe(Paquete::TIPO_MASIVA)
        ->and($lote->paquetes[0]->cantidad_facturas)->toBe(3)
        ->and(Factura::whereIn('id', $facturas->pluck('id'))->whereNull('paquete_id')->count())->toBe(0);
    Queue::assertPushed(EnviarPaqueteMasivo::class, 1);

    expect(emitirMasiva($pv)->estado)->toBe(Factura::ESTADO_PENDIENTE);
    expect(fn () => app(GestorMasiva::class)->cerrar($pv))->toThrow(RuntimeException::class);
});

test('el paquete viaja por recepcionMasivaFactura y al validarse avisa por cada factura', function () {
    Queue::fake();
    $pv = pvMasivo();
    app(GestorMasiva::class)->abrir($pv);
    emitirMasiva($pv);
    emitirMasiva($pv);
    $paquete = app(GestorMasiva::class)->cerrar($pv)->paquetes[0];

    $servicio = sinMasivo(
        respuestaMasiva(['transaccion' => true, 'codigoRecepcion' => 'REC-9', 'codigoEstado' => 901]),
        respuestaMasiva(['transaccion' => true, 'codigoEstado' => 908, 'codigoDescripcion' => 'VALIDADA']),
    );

    app()->call([new EnviarPaqueteMasivo($paquete->id), 'handle']);

    $servicio->shouldHaveReceived('recepcionarMasiva')->withArgs(fn (array $datos, string $tar) => $datos['codigoEmision'] === 3
        && $datos['cantidadFacturas'] === 2 && $datos['codigoDocumentoSector'] === 1 && str_ends_with($tar, str_repeat("\0", 1024)));
    expect($paquete->fresh())->estado->toBe('ENVIADO')->codigo_recepcion->toBe('REC-9');
    Queue::assertPushed(ValidarPaqueteMasivo::class);

    app()->call([new ValidarPaqueteMasivo($paquete->id), 'handle']);

    expect($paquete->fresh()->estado)->toBe('VALIDADO')
        ->and(Factura::where('paquete_id', $paquete->id)->pluck('estado')->unique()->all())->toBe([Factura::ESTADO_VALIDADA]);
    Queue::assertPushed(NotificarWebhook::class, fn ($job) => $job->evento === 'factura.validada');
    Queue::assertPushed(NotificarWebhook::class, 2);
});

test('un paquete rechazado deja sus facturas observadas con el motivo del SIN', function () {
    Queue::fake();
    $pv = pvMasivo();
    app(GestorMasiva::class)->abrir($pv);
    emitirMasiva($pv);
    $paquete = app(GestorMasiva::class)->cerrar($pv)->paquetes[0];

    sinMasivo(respuestaMasiva(['transaccion' => false, 'mensajesList' => (object) ['codigo' => 1037, 'descripcion' => 'NIT NO AUTORIZADO PARA EMISION MASIVA']]));

    app()->call([new EnviarPaqueteMasivo($paquete->id), 'handle']);

    $factura = Factura::where('paquete_id', $paquete->id)->sole();
    expect($paquete->fresh()->estado)->toBe('OBSERVADO')
        ->and($factura->estado)->toBe(Factura::ESTADO_OBSERVADA)
        ->and($factura->motivo_observacion)->toBe('[1037] NIT NO AUTORIZADO PARA EMISION MASIVA');
    Queue::assertPushed(NotificarWebhook::class, fn ($job) => $job->evento === 'factura.observada');
});

test('la validacion pendiente se reintenta sin tocar las facturas', function () {
    Queue::fake();
    $pv = pvMasivo();
    $paquete = Paquete::create(['empresa_id' => $pv->sucursal->empresa_id, 'punto_venta_id' => $pv->id, 'tipo' => Paquete::TIPO_MASIVA,
        'cantidad_facturas' => 1, 'estado' => 'ENVIADO', 'codigo_recepcion' => 'REC-1']);
    Factura::factory()->create(['punto_venta_id' => $pv->id, 'paquete_id' => $paquete->id, 'estado' => Factura::ESTADO_ENVIADA]);
    sinMasivo(validacion: respuestaMasiva(['transaccion' => true, 'codigoEstado' => 901, 'codigoDescripcion' => 'PENDIENTE']));

    app()->call([new ValidarPaqueteMasivo($paquete->id), 'handle']);

    expect($paquete->fresh()->estado)->toBe('ENVIADO')
        ->and(Factura::sole()->estado)->toBe(Factura::ESTADO_ENVIADA);
});

test('la API abre, consulta y cierra el lote', function () {
    Queue::fake();
    $pv = pvMasivo();
    $token = $pv->sucursal->empresa->emitirTokenApi('Test', 'caja')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/masiva/0/0/abrir')->assertCreated()->assertJsonPath('lote.estado', 'ABIERTO');
    emitirMasiva($pv);
    $this->withToken($token)->getJson('/api/v1/masiva/0/0')->assertJsonPath('lote.facturas_por_estado', ['MASIVA' => 1]);
    $this->withToken($token)->postJson('/api/v1/masiva/0/0/cerrar')->assertSuccessful()
        ->assertJsonPath('lote.estado', 'CERRADO')
        ->assertJsonPath('lote.paquetes.0.cantidad_facturas', 1);
    $this->withToken($token)->postJson('/api/v1/masiva/0/0/cerrar')->assertConflict()->assertJsonPath('error', 'SIN_LOTE_ABIERTO');
    $this->withToken($token)->postJson('/api/v1/masiva/0/9/abrir')->assertNotFound();
});
