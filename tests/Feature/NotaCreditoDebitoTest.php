<?php

use App\Exceptions\FacturaInvalidaException;
use App\Exceptions\SiatException;
use App\Jobs\EnviarFacturaAlSiat;
use App\Models\Certificado;
use App\Models\Cufd;
use App\Models\Empresa;
use App\Models\EventoSignificativo;
use App\Models\Factura;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Services\Factura\AnuladorFactura;
use App\Services\Factura\ConstructorXml;
use App\Services\Factura\EmisorFactura;
use App\Services\Factura\EmisorNotaCreditoDebito;
use App\Services\Factura\GeneradorPdf;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\ServicioFacturacion;
use App\Services\Siat\SiatClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Factura VALIDADA de dos lineas, emitida por el flujo real:
 *   linea 1: 2 x 50 con 10 de descuento = 90
 *   linea 2: 1 x 100                    = 100
 *
 * @param  array<string, mixed>  $extra  campos de la venta a pisar.
 */
function facturaParaNota(array $extra = []): Factura
{
    Queue::fake();
    $empresa = Empresa::factory()->enProduccion()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $pv = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);
    Cufd::factory()->create(['punto_venta_id' => $pv->id]);
    Certificado::factory()->for($empresa)->firmable()->create();

    $factura = app(EmisorFactura::class)->emitir($empresa, array_merge([
        'sucursal' => 0,
        'punto_venta' => 0,
        'comprador' => ['tipo_documento' => 5, 'numero_documento' => '1020304', 'razon_social' => 'FERRETERIA SOL'],
        'metodo_pago' => 1,
        'items' => [
            ['codigo_producto_sin' => 99100, 'codigo_interno' => 'MART', 'descripcion' => 'Martillo', 'cantidad' => 2, 'unidad_medida' => 57, 'precio_unitario' => 50, 'descuento' => 10],
            ['codigo_producto_sin' => 99100, 'codigo_interno' => 'TAL', 'descripcion' => 'Taladro', 'cantidad' => 1, 'unidad_medida' => 57, 'precio_unitario' => 100],
        ],
    ], $extra));

    $factura->update(['estado' => Factura::ESTADO_VALIDADA, 'validada_en' => now()]);

    return $factura->fresh();
}

/**
 * @param  list<array{linea: int, cantidad: int|float}>  $items
 */
function devolver(Factura $factura, array $items, ?string $referencia = null): Factura
{
    return app(EmisorNotaCreditoDebito::class)->emitir($factura, [
        'items' => $items,
        'referencia_externa' => $referencia,
    ]);
}

function tokenParaNotas(Empresa $empresa, string $perfil = 'administracion'): string
{
    return $empresa->emitirTokenApi('Test', $perfil)->plainTextToken;
}

/**
 * Errores de negocio con los que se rechaza una devolucion.
 */
function erroresDeNota(callable $emitir): string
{
    try {
        $emitir();
    } catch (FacturaInvalidaException $e) {
        return implode(' ', $e->errores);
    }

    return '';
}

// --- Emision -----------------------------------------------------------------------

test('la nota toma precio y descuento de la factura y revierte el 13% de lo devuelto', function () {
    $factura = facturaParaNota();

    $nota = devolver($factura, [['linea' => 1, 'cantidad' => 1]]);

    expect($nota->esNotaCreditoDebito())->toBeTrue()
        ->and($nota->factura_origen_id)->toBe($factura->id)
        ->and($nota->estado)->toBe(Factura::ESTADO_PENDIENTE)
        ->and((float) $nota->subtotal)->toBe(45.0)
        ->and((float) $nota->descuento_global)->toBe(0.0)
        ->and((float) $nota->monto_total)->toBe(45.0)
        ->and((float) $nota->monto_efectivo_credito_debito)->toBe(5.85)
        ->and($nota->comprador_razon_social)->toBe('FERRETERIA SOL');

    // Todos los items originales (1) y despues los devueltos (2).
    $items = $nota->items;
    expect($items->pluck('codigo_detalle_transaccion')->all())->toBe([1, 1, 2])
        ->and((float) $items[2]->cantidad)->toBe(1.0)
        ->and((float) $items[2]->descuento)->toBe(5.0)
        ->and((float) $items[2]->subtotal)->toBe(45.0)
        ->and($items[2]->item_origen_id)->toBe($factura->items[0]->id);

    Queue::assertPushed(EnviarFacturaAlSiat::class, fn ($job) => $job->facturaId === $nota->id);
});

test('el descuento adicional de la factura se prorratea en la nota', function () {
    $factura = facturaParaNota(['descuento_global' => 19]);

    // 100 de 190 devueltos: le toca 10 del descuento de 19.
    $nota = devolver($factura, [['linea' => 2, 'cantidad' => 1]]);

    expect((float) $nota->subtotal)->toBe(100.0)
        ->and((float) $nota->descuento_global)->toBe(10.0)
        ->and((float) $nota->monto_total)->toBe(90.0)
        ->and((float) $nota->monto_efectivo_credito_debito)->toBe(11.7);
});

test('las notas llevan su propio correlativo y un CUF de documento de ajuste', function () {
    $factura = facturaParaNota();

    $primera = devolver($factura, [['linea' => 1, 'cantidad' => 1]]);
    $segunda = devolver($factura, [['linea' => 2, 'cantidad' => 1]]);

    $pv = $factura->puntoVenta->fresh();

    expect([$primera->numero_factura, $segunda->numero_factura])->toBe([1, 1 + 1])
        ->and($pv->siguiente_nota)->toBe(3)
        // El correlativo de facturas no se toca.
        ->and($pv->siguiente_factura)->toBe($factura->numero_factura + 1)
        ->and($primera->cuf)->not->toBe($factura->cuf);
});

test('no se devuelve mas de lo vendido, contando las notas anteriores vigentes', function () {
    $factura = facturaParaNota();
    devolver($factura, [['linea' => 1, 'cantidad' => 1.5]]);

    expect(erroresDeNota(fn () => devolver($factura, [['linea' => 1, 'cantidad' => 1]])))
        ->toContain('Linea 1: se piden devolver 1 y quedan 0.5 por devolver.');

    // La misma linea dos veces en el pedido se suma.
    expect(erroresDeNota(fn () => devolver($factura, [['linea' => 2, 'cantidad' => 0.6], ['linea' => 2, 'cantidad' => 0.6]])))
        ->toContain('Linea 2: se piden devolver 1.2 y quedan 1 por devolver.');
});

test('una nota anulada libera lo que devolvia', function () {
    $factura = facturaParaNota();
    $nota = devolver($factura, [['linea' => 2, 'cantidad' => 1]]);
    $nota->update(['estado' => Factura::ESTADO_ANULADA]);

    expect(devolver($factura, [['linea' => 2, 'cantidad' => 1]])->id)->not->toBe($nota->id);
});

test('se rechaza la nota que el SIN no aceptaria', function (string $caso, string $error) {
    $factura = facturaParaNota($caso === 'gift card' ? ['gift_card' => 20] : []);

    match ($caso) {
        'no validada' => $factura->update(['estado' => Factura::ESTADO_RECIBIDA]),
        'contingencia' => EventoSignificativo::create([
            'empresa_id' => $factura->empresa_id, 'punto_venta_id' => $factura->punto_venta_id,
            'codigo_evento' => 1, 'descripcion' => 'Corte', 'fecha_inicio' => now(), 'estado' => 'ABIERTO',
        ]),
        default => null,
    };

    $origen = $caso === 'nota sobre nota'
        ? tap(devolver($factura, [['linea' => 1, 'cantidad' => 1]]))->update(['estado' => Factura::ESTADO_VALIDADA])
        : $factura->fresh();

    $linea = $caso === 'linea inexistente' ? 3 : 1;

    expect(erroresDeNota(fn () => devolver($origen, [['linea' => $linea, 'cantidad' => 1]])))->toContain($error);
})->with([
    ['no validada', 'Solo se emite una nota sobre una factura VALIDADA'],
    ['linea inexistente', 'La factura no tiene linea 3: tiene 2.'],
    ['contingencia', 'solo se emiten en linea'],
    ['gift card', 'gift card'],
    ['nota sobre nota', 'no se puede ajustar con otra nota'],
]);

test('la misma referencia devuelve la misma nota y no se reutiliza la de otro documento', function () {
    $factura = facturaParaNota(['referencia_externa' => 'VTA-1']);

    $nota = devolver($factura, [['linea' => 1, 'cantidad' => 1]], 'DEV-1');

    expect(devolver($factura, [['linea' => 1, 'cantidad' => 1]], 'DEV-1')->id)->toBe($nota->id)
        ->and(Factura::count())->toBe(2)
        ->and(erroresDeNota(fn () => devolver($factura, [['linea' => 2, 'cantidad' => 1]], 'VTA-1')))
        ->toContain('La referencia VTA-1 ya la usa otro documento');
});

// --- XML y SOAP --------------------------------------------------------------------

test('el XML de la nota sigue la secuencia del XSD y referencia la factura', function () {
    $factura = facturaParaNota();
    $nota = devolver($factura, [['linea' => 1, 'cantidad' => 1]]);

    $doc = new DOMDocument;
    $doc->loadXML(app(ConstructorXml::class)->construir($nota->fresh()));

    $hijos = fn (DOMNode $nodo) => array_values(array_map(fn ($n) => $n->nodeName, iterator_to_array($nodo->childNodes)));
    $texto = fn (string $etiqueta, int $i = 0) => $doc->getElementsByTagName($etiqueta)->item($i)->textContent;

    expect($doc->documentElement->nodeName)->toBe('notaFiscalElectronicaCreditoDebito')
        ->and($doc->documentElement->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'noNamespaceSchemaLocation'))
        ->toBe('notaFiscalElectronicaCreditoDebito.xsd')
        ->and($hijos($doc->getElementsByTagName('cabecera')->item(0)))->toBe([
            'nitEmisor', 'razonSocialEmisor', 'municipio', 'telefono', 'numeroNotaCreditoDebito', 'cuf', 'cufd',
            'codigoSucursal', 'direccion', 'codigoPuntoVenta', 'fechaEmision', 'nombreRazonSocial',
            'codigoTipoDocumentoIdentidad', 'numeroDocumento', 'complemento', 'codigoCliente', 'numeroFactura',
            'numeroAutorizacionCuf', 'fechaEmisionFactura', 'montoTotalOriginal', 'montoTotalDevuelto',
            'montoDescuentoCreditoDebito', 'montoEfectivoCreditoDebito', 'codigoExcepcion', 'leyenda', 'usuario',
            'codigoDocumentoSector',
        ])
        ->and($hijos($doc->getElementsByTagName('detalle')->item(0)))->toBe([
            'actividadEconomica', 'codigoProductoSin', 'codigoProducto', 'descripcion', 'cantidad', 'unidadMedida',
            'precioUnitario', 'montoDescuento', 'subTotal', 'codigoDetalleTransaccion',
        ])
        ->and($texto('numeroFactura'))->toBe((string) $factura->numero_factura)
        ->and($texto('numeroAutorizacionCuf'))->toBe($factura->cuf)
        ->and($texto('montoTotalOriginal'))->toBe('190.00')
        ->and($texto('montoTotalDevuelto'))->toBe('45.00')
        ->and($texto('montoEfectivoCreditoDebito'))->toBe('5.85')
        ->and($texto('codigoDocumentoSector'))->toBe('24')
        ->and($doc->getElementsByTagName('detalle')->length)->toBe(3)
        ->and($texto('codigoDetalleTransaccion', 2))->toBe('2');
});

test('la nota viaja al servicio de documentos de ajuste con tipo de documento 3', function (string $metodo, string $operacion, string $envoltorio) {
    $factura = facturaParaNota();
    $nota = devolver($factura, [['linea' => 1, 'cantidad' => 1]])->fresh(['empresa', 'puntoVenta.sucursal']);

    $servicio = new class($nota->empresa, new SiatClient($nota->empresa)) extends ServicioFacturacion
    {
        /** @var list<mixed> */
        public array $llamada = [];

        protected function invocar(string $claveServicio, string $operacion, array $parametros): mixed
        {
            $this->llamada = [$claveServicio, $operacion, array_key_first($parametros), reset($parametros)];

            return null;
        }
    };

    match ($metodo) {
        'recepcionarFactura', 'verificarEstado', 'revertirAnulacion' => $servicio->{$metodo}($nota, 'CUFD', 'CUIS'),
        'anular' => $servicio->anular($nota, 1, 'CUFD', 'CUIS'),
    };

    [$clave, $nombre, $wrapper, $solicitud] = $servicio->llamada;

    expect($clave)->toBe('documento_ajuste')
        ->and(config("siat.servicios.{$clave}"))->toBe('ServicioFacturacionDocumentoAjuste')
        ->and($nombre)->toBe($operacion)
        ->and($wrapper)->toBe($envoltorio)
        ->and($solicitud['tipoFacturaDocumento'])->toBe(3)
        ->and($solicitud['codigoDocumentoSector'])->toBe(24);
})->with([
    ['recepcionarFactura', 'recepcionDocumentoAjuste', 'SolicitudServicioRecepcionDocumentoAjuste'],
    ['verificarEstado', 'verificacionEstadoDocumentoAjuste', 'SolicitudServicioVerificacionEstadoDocumentoAjuste'],
    ['anular', 'anulacionDocumentoAjuste', 'SolicitudServicioAnulacionDocumentoAjuste'],
    ['revertirAnulacion', 'reversionAnulacionDocumentoAjuste', 'SolicitudServicioReversionAnulacionDocumentoAjuste'],
]);

test('si el SIAT no responde, la nota queda pendiente y no cae a contingencia', function () {
    $factura = facturaParaNota();
    $nota = devolver($factura, [['linea' => 1, 'cantidad' => 1]]);
    $nota->update(['ruta_pdf' => 'ya.pdf']);

    $servicio = Mockery::mock(ServicioFacturacion::class);
    $servicio->shouldReceive('recepcionarFactura')->andThrow(new SiatException('timeout'));
    $this->mock(FabricaServicios::class, fn ($mock) => $mock->shouldReceive('facturacion')->andReturn($servicio));

    // Ultimo intento: una factura ya se derivaria a contingencia.
    $job = Mockery::mock(EnviarFacturaAlSiat::class, [$nota->id])->makePartial();
    $job->shouldReceive('attempts')->andReturn(3);
    app()->call([$job, 'handle']);

    expect($nota->fresh()->estado)->toBe(Factura::ESTADO_PENDIENTE)
        ->and(EventoSignificativo::count())->toBe(0);
});

// --- Anulacion ---------------------------------------------------------------------

test('una factura con notas vigentes no se anula; anuladas las notas, si', function () {
    $factura = facturaParaNota();
    $nota = devolver($factura, [['linea' => 1, 'cantidad' => 1]]);
    $anulador = app(AnuladorFactura::class);

    expect($anulador->esAnulable($factura))->toBeFalse()
        ->and($anulador->motivoNoAnulable($factura))->toBe('La factura tiene notas de credito-debito vigentes: anule primero las notas.');

    $nota->update(['estado' => Factura::ESTADO_ANULADA]);

    expect($anulador->esAnulable($factura))->toBeTrue();
});

// --- API ---------------------------------------------------------------------------

test('POST /facturas/{cuf}/notas emite la nota y la devuelve con su factura de origen', function () {
    $factura = facturaParaNota();

    $respuesta = $this->withToken(tokenParaNotas($factura->empresa))
        ->withHeader('X-Idempotency-Key', 'DEV-77')
        ->postJson("/api/v1/facturas/{$factura->cuf}/notas", ['items' => [['linea' => 1, 'cantidad' => 2]]]);

    $respuesta->assertCreated()
        ->assertJsonPath('nota.tipo_documento', 'NOTA_CREDITO_DEBITO')
        ->assertJsonPath('nota.documento_sector', 24)
        ->assertJsonPath('nota.factura_origen.cuf', $factura->cuf)
        ->assertJsonPath('nota.monto_devuelto', 90)
        ->assertJsonPath('nota.monto_credito_debito', 11.7)
        ->assertJsonPath('nota.referencia_externa', 'DEV-77');

    $cufNota = $respuesta->json('nota.cuf');
    $token = tokenParaNotas($factura->empresa, 'consulta');

    // La nota se consulta con los endpoints de facturas, y se lista aparte.
    $this->withToken($token)->getJson("/api/v1/facturas/{$cufNota}")->assertSuccessful()
        ->assertJsonPath('factura.tipo_documento', 'NOTA_CREDITO_DEBITO');
    $this->withToken($token)->getJson("/api/v1/facturas/{$factura->cuf}/notas")->assertSuccessful()
        ->assertJsonCount(1, 'notas')
        ->assertJsonPath('notas.0.cuf', $cufNota);
    $this->withToken($token)->getJson('/api/v1/facturas')->assertSuccessful()
        ->assertJsonCount(1, 'facturas')
        ->assertJsonPath('facturas.0.tipo_documento', 'FACTURA');
    $this->withToken($token)->getJson('/api/v1/facturas?tipo=nota')->assertSuccessful()
        ->assertJsonCount(1, 'facturas')
        ->assertJsonPath('facturas.0.cuf', $cufNota);
    $this->withToken($token)->getJson('/api/v1/facturas?tipo=todos')->assertJsonCount(2, 'facturas');
});

test('POST /facturas/{cuf}/notas: caja sin permiso, devolucion invalida o factura ajena', function (string $caso, int $estado) {
    $factura = facturaParaNota();

    [$token, $cuf, $cuerpo] = match ($caso) {
        'caja' => [tokenParaNotas($factura->empresa, 'caja'), $factura->cuf, ['items' => [['linea' => 1, 'cantidad' => 1]]]],
        'sin items' => [tokenParaNotas($factura->empresa), $factura->cuf, ['items' => []]],
        'de mas' => [tokenParaNotas($factura->empresa), $factura->cuf, ['items' => [['linea' => 1, 'cantidad' => 3]]]],
        'ajena' => [tokenParaNotas(Empresa::factory()->enProduccion()->create()), $factura->cuf, ['items' => [['linea' => 1, 'cantidad' => 1]]]],
    };

    $respuesta = $this->withToken($token)->postJson("/api/v1/facturas/{$cuf}/notas", $cuerpo)->assertStatus($estado);

    if ($caso === 'de mas') {
        $respuesta->assertJsonPath('error', 'NOTA_INVALIDA');
    }

    expect(Factura::count())->toBe(1);
})->with([
    ['caja', 403],
    ['sin items', 422],
    ['de mas', 422],
    ['ajena', 404],
]);

// --- Representacion grafica ----------------------------------------------------------

test('el PDF de la nota identifica la factura y separa originales de devueltos', function () {
    $factura = facturaParaNota();
    $nota = devolver($factura, [['linea' => 1, 'cantidad' => 1]]);

    $html = view('factura.pdf', app(GeneradorPdf::class)->datos($nota->fresh()))->render();

    expect($html)->toContain('NOTA CRÉDITO DÉBITO')
        ->toContain('DATOS DE LA TRANSACCIÓN ORIGINAL')
        ->toContain('DETALLE DE LA DEVOLUCIÓN O RESCISIÓN DE SERVICIO')
        ->toContain($factura->cuf)
        ->toContain('5.85');

    // El ticket de una nota es la hoja carta.
    $this->get($nota->urlPublica('rollo'))->assertSuccessful()
        ->assertHeader('Content-Disposition', 'inline; filename="nota-credito-debito-1.pdf"');
});
