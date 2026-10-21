<?php

use App\Exceptions\FacturaInvalidaException;
use App\Jobs\AnularFacturaEnSiat;
use App\Jobs\EjecutarCasoPrueba;
use App\Jobs\EnviarFacturaAlSiat;
use App\Jobs\EnviarPaqueteContingencia;
use App\Jobs\NotificarWebhook;
use App\Jobs\VerificarEstadoFactura;
use App\Models\Certificado;
use App\Models\Cufd;
use App\Models\Cuis;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\FacturaItem;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Services\Factura\CalculadorTotales;
use App\Services\Factura\ConstructorXml;
use App\Services\Factura\EmisorFactura;
use App\Services\Factura\FirmadorXml;
use App\Services\Factura\GeneradorCuf;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\GestorCodigos;
use App\Services\Siat\RespuestaSiat;
use App\Services\Siat\ServicioFacturacion;
use App\Services\Siat\SiatClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Nucleo de la facturacion
|--------------------------------------------------------------------------
|
| Las piezas donde un error NO da error en local: la factura se emite igual y
| el SIN la rechaza despues. CUF, XML, firma, hash, montos y lectura de las
| respuestas del SIN. Los valores esperados estan calculados a mano o
| verificados contra un sistema que factura en produccion, nunca copiados de
| la implementacion.
|
*/

// --- CUF: modulo 11 --------------------------------------------------------------

test('el digito verificador es el resto de modulo 11', function (string $cadena, int $esperado) {
    expect(app(GeneradorCuf::class)->digitoVerificador($cadena))->toBe($esperado);
})->with([
    '1 -> 1*2 = 2' => ['1', 2],
    '3 -> 3*2 = 6' => ['3', 6],
    '7 -> 14 % 11 = 3' => ['7', 3],
    '5 -> resto 10 se escribe 1' => ['5', 1],
    '99 -> 9*2 + 9*3 = 45, 45 % 11 = 1' => ['99', 1],
    '10 -> 0*2 + 1*3 = 3' => ['10', 3],
    '53 ceros -> 0' => [str_repeat('0', 53), 0],
    'el peso vuelve a 2 tras el 9' => ['100000000', 2],
    'y sigue en 3' => ['1000000000', 3],
]);

// --- CUF completo ----------------------------------------------------------------

test('el CUF es la cadena de 53 digitos + verificador en base 16 + codigo de control', function () {
    $generador = app(GeneradorCuf::class);

    $cuf = $generador->generar([
        'nit' => 7633685015,
        'fecha' => '20260809120000000',
        'sucursal' => 0,
        'modalidad' => 1,
        'tipo_emision' => 1,
        'tipo_factura' => 1,
        'tipo_documento_sector' => 1,
        'numero_factura' => 1,
        'punto_venta' => 0,
    ], 'CTRL01');

    // Escrita a mano campo por campo con sus anchos: 13+17+4+1+1+1+2+10+4.
    $cadena = '0007633685015'.'20260809120000000'.'0000'.'1'.'1'.'1'.'01'.'0000000001'.'0000';
    expect(strlen($cadena))->toBe(53);

    $esperada = ltrim($cadena.$generador->digitoVerificador($cadena), '0');

    // Se deshace la base 16 con bcmath, independiente del codigo bajo prueba.
    $hex = substr($cuf, 0, -strlen('CTRL01'));
    $decimal = '0';
    foreach (str_split($hex) as $digito) {
        $decimal = bcadd(bcmul($decimal, '16'), (string) hexdec($digito));
    }

    expect($cuf)->toEndWith('CTRL01')
        ->and($hex)->toMatch('/^[0-9A-F]+$/')
        ->and($decimal)->toBe($esperada);
});

test('cambiar el tipo de emision cambia el CUF', function () {
    $datos = ['nit' => 1, 'fecha' => '20260101000000000', 'sucursal' => 0, 'modalidad' => 1,
        'tipo_emision' => 1, 'tipo_factura' => 1, 'tipo_documento_sector' => 1, 'numero_factura' => 1, 'punto_venta' => 0];

    $generador = app(GeneradorCuf::class);

    expect($generador->generar($datos, 'X'))
        ->not->toBe($generador->generar(['tipo_emision' => 2] + $datos, 'X'));
});

// --- Totales ---------------------------------------------------------------------

test('los totales redondean a 2 decimales por linea y en total', function () {
    $totales = app(CalculadorTotales::class)->calcular([
        ['cantidad' => 3, 'precio_unitario' => 0.333],
        ['cantidad' => 1.5, 'precio_unitario' => 10.10, 'descuento' => 0.15],
    ]);

    // 3 * 0.333 = 0.999 -> 1.00 ; 1.5 * 10.10 = 15.15 - 0.15 = 15.00
    // Neto 16.00, IVA 13% = 2.08, a pagar 18.08.
    expect($totales['items'])->toBe([['subtotal' => 1.0], ['subtotal' => 15.0]])
        ->and($totales['subtotal'])->toBe(16.0)
        ->and($totales['total_precio_neto'])->toBe(16.0)
        ->and($totales['iva'])->toBe(2.08)
        ->and($totales['monto_total'])->toBe(18.08);
});

// --- XML ---------------------------------------------------------------------------

function facturaParaXml(): Factura
{
    $empresa = Empresa::factory()->enProduccion()->create(['razon_social' => 'TIENDA & CIA <SRL>']);
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $pv = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);
    $cufd = Cufd::factory()->create(['punto_venta_id' => $pv->id]);

    $factura = Factura::factory()->create([
        'empresa_id' => $empresa->id,
        'punto_venta_id' => $pv->id,
        'cufd_id' => $cufd->id,
        'comprador_complemento' => null,
        'numero_tarjeta' => null,
        'gift_card' => 0,
    ]);

    FacturaItem::factory()->for($factura)->create(['codigo_actividad' => '620100', 'numero_serie' => null, 'numero_imei' => null]);

    return $factura->fresh();
}

test('el XML declara el esquema del SIN en la raiz', function () {
    $xml = app(ConstructorXml::class)->construir(facturaParaXml());

    expect($xml)->toContain('<facturaElectronicaCompraVenta')
        ->and($xml)->toContain('xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"')
        ->and($xml)->toContain('xsi:noNamespaceSchemaLocation="facturaElectronicaCompraVenta.xsd"');
});

test('los vacios viajan como xsi:nil, los ceros con su valor y el texto escapado', function () {
    $xml = app(ConstructorXml::class)->construir(facturaParaXml());

    expect($xml)->toContain('<numeroTarjeta xsi:nil="true"/>')
        ->and($xml)->toContain('<numeroSerie xsi:nil="true"/>')
        ->and($xml)->toContain('<montoGiftCard>0.00</montoGiftCard>')
        ->and($xml)->toContain('<codigoExcepcion>0</codigoExcepcion>')
        ->and($xml)->toContain('<razonSocialEmisor>TIENDA &amp; CIA &lt;SRL&gt;</razonSocialEmisor>');

    // Y es XML bien formado.
    expect((new DOMDocument)->loadXML($xml))->toBeTrue();
});

test('sin complemento va "0": el SIN V3 rechaza el elemento vacio con [1068] y el XSD no deja xsi:nil', function () {
    $xml = app(ConstructorXml::class)->construir(facturaParaXml());

    expect($xml)->toContain('</numeroDocumento><complemento>0</complemento><codigoCliente>');
});

test('el complemento con valor viaja en su lugar de la secuencia', function () {
    $factura = facturaParaXml();
    $factura->update(['comprador_complemento' => '1A']);

    $xml = app(ConstructorXml::class)->construir($factura->fresh());

    expect($xml)->toContain('</numeroDocumento><complemento>1A</complemento><codigoCliente>');
});

test('la cabecera y el detalle respetan la secuencia completa del XSD', function (string $nodo, array $orden) {
    $doc = new DOMDocument;
    $doc->loadXML(app(ConstructorXml::class)->construir(facturaParaXml()));

    $hijos = [];
    foreach ($doc->getElementsByTagName($nodo)->item(0)->childNodes as $hijo) {
        $hijos[] = $hijo->nodeName;
    }

    // Exactamente estos, en este orden: ni falta ni sobra ninguno.
    expect($hijos)->toBe($orden);
})->with([
    'cabecera' => ['cabecera', ['nitEmisor', 'razonSocialEmisor', 'municipio', 'telefono', 'numeroFactura',
        'cuf', 'cufd', 'codigoSucursal', 'direccion', 'codigoPuntoVenta', 'fechaEmision',
        'nombreRazonSocial', 'codigoTipoDocumentoIdentidad', 'numeroDocumento', 'complemento',
        'codigoCliente', 'codigoMetodoPago', 'numeroTarjeta', 'subTotalNeto', 'totalPrecioNeto', 'iva',
        'montoaPagar', 'codigoMoneda', 'tipoCambio', 'montoTotalMoneda', 'montoGiftCard', 'descuentoAdicional',
        'codigoExcepcion', 'leyenda', 'usuario', 'codigoInterno', 'codigoDocumentoSector']],
    'detalle' => ['detalle', ['actividadEconomica', 'codigoProductoSin', 'codigoProducto', 'descripcion',
        'cantidad', 'unidadMedida', 'precioNetoUnitario', 'montoDescuento', 'subTotal', 'numeroSerie', 'numeroImei']],
]);

test('el XML de la factura valida contra el XSD V3 oficial del SIN', function (array $cambios) {
    $factura = facturaParaXml();
    $factura->update($cambios);

    $xml = app(ConstructorXml::class)->construir($factura->fresh());

    expect(erroresContraXsdV3($xml, 'facturaElectronicaCompraVenta.xsd'))->toBe([]);
})->with([
    'sin complemento ni tarjeta' => [[]],
    'con complemento' => [['comprador_complemento' => '1A']],
]);

test('el codigoInterno sale del CUFD de la factura, y si el CUFD no lo trajo, de la empresa', function () {
    $factura = facturaParaXml();
    $factura->empresa->update(['codigo_interno' => 'B7']);
    $cufd = Cufd::factory()->for($factura->puntoVenta)->create(['codigo_interno' => null]);
    $factura->update(['cufd_id' => $cufd->id]);

    // El CUFD pedido por /v2 no trae codigoInterno: queda el de la empresa.
    expect(app(ConstructorXml::class)->construir($factura->fresh()))
        ->toContain('<usuario>caja-01</usuario><codigoInterno>B7</codigoInterno><codigoDocumentoSector>');

    // Por /sfd el SIN lo devuelve con el CUFD y es el que exige: con otro
    // responde [1077] "Codigo interno esperado C enviado A".
    $cufd->update(['codigo_interno' => 'C']);

    expect(app(ConstructorXml::class)->construir($factura->fresh()))
        ->toContain('<codigoInterno>C</codigoInterno>');
});

test('el CUFD guarda el codigoInterno que devuelve el SIN', function () {
    $puntoVenta = PuntoVenta::factory()->create();
    $cuis = Cuis::factory()->for($puntoVenta)->create();

    $cufd = app(GestorCodigos::class)->guardarCufd($puntoVenta, $cuis, (object) ['RespuestaCufd' => (object) [
        'codigo' => 'CUFD-X', 'codigoControl' => 'CTRL', 'codigoInterno' => 'C', 'direccion' => 'AV', 'fechaVigencia' => now()->addDay()->format('Y-m-d\\TH:i:s.vP'), 'transaccion' => true,
    ]]);

    expect($cufd->codigo_interno)->toBe('C');
});

test('la fecha del XML lleva milisegundos y coincide con la de emision', function () {
    $factura = facturaParaXml();
    $factura->update(['fecha_emision' => '2026-10-11 09:15:42']);

    $xml = app(ConstructorXml::class)->construir($factura->fresh());

    expect($xml)->toContain('<fechaEmision>2026-10-11T09:15:42.000</fechaEmision>');
});

// --- Firma -------------------------------------------------------------------------

test('la firma verifica con el certificado incrustado y su digest cubre el documento', function () {
    $certificado = Certificado::factory()->firmable()->create();
    $xml = app(ConstructorXml::class)->construir(facturaParaXml());

    $firmado = app(FirmadorXml::class)->firmar($xml, $certificado);

    $doc = new DOMDocument;
    $doc->loadXML($firmado);
    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

    // 1. SignatureValue verifica sobre el SignedInfo canonicalizado EN SU
    //    LUGAR, con la clave publica del X509Certificate incrustado.
    $signedInfo = $xpath->query('//ds:SignedInfo')->item(0);
    $firma = base64_decode($xpath->query('//ds:SignatureValue')->item(0)->textContent);
    $pem = "-----BEGIN CERTIFICATE-----\n"
        .chunk_split($xpath->query('//ds:X509Certificate')->item(0)->textContent, 64, "\n")
        ."-----END CERTIFICATE-----\n";

    expect(openssl_verify($signedInfo->C14N(), $firma, $pem, OPENSSL_ALGO_SHA256))->toBe(1);

    // 2. El DigestValue es el SHA-256 del documento SIN el bloque Signature
    //    (transformacion enveloped).
    $digest = $xpath->query('//ds:DigestValue')->item(0)->textContent;
    $signature = $xpath->query('//ds:Signature')->item(0);
    $signature->parentNode->removeChild($signature);

    expect(base64_encode(hash('sha256', $doc->documentElement->C14N(), true)))->toBe($digest);
});

test('una factura alterada despues de firmar ya no coincide con su digest', function () {
    $certificado = Certificado::factory()->firmable()->create();
    $firmado = app(FirmadorXml::class)->firmar(app(ConstructorXml::class)->construir(facturaParaXml()), $certificado);

    $alterado = preg_replace('#<montoaPagar>[^<]+</montoaPagar>#', '<montoaPagar>1.00</montoaPagar>', $firmado);

    expect($alterado)->not->toBe($firmado);

    $doc = new DOMDocument;
    $doc->loadXML($alterado);
    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
    $digest = $xpath->query('//ds:DigestValue')->item(0)->textContent;
    $signature = $xpath->query('//ds:Signature')->item(0);
    $signature->parentNode->removeChild($signature);

    expect(base64_encode(hash('sha256', $doc->documentElement->C14N(), true)))->not->toBe($digest);
});

// --- Envio al SIN --------------------------------------------------------------------

/**
 * ServicioFacturacion real con la invocacion SOAP interceptada: deja ver la
 * solicitud exacta que se le mandaria al SIN.
 */
function servicioQueCaptura(Factura $factura): ServicioFacturacion
{
    return new class($factura->empresa, new SiatClient($factura->empresa)) extends ServicioFacturacion
    {
        /** @var array<string, mixed> */
        public array $solicitud = [];

        protected function invocar(string $claveServicio, string $operacion, array $parametros): mixed
        {
            $this->solicitud = reset($parametros);

            return null;
        }
    };
}

test('la recepcion manda el XML en gzip, el hash DEL GZIP, el CUIS y el CUFD', function () {
    $factura = facturaParaXml();
    $factura->update(['xml_firmado' => '<facturaElectronicaCompraVenta/>']);

    $servicio = servicioQueCaptura($factura);
    $servicio->recepcionarFactura($factura, 'CUFD-1', 'CUIS-1');
    $enviado = $servicio->solicitud;

    expect(gzdecode($enviado['archivo']))->toBe('<facturaElectronicaCompraVenta/>')
        ->and($enviado['hashArchivo'])->toBe(hash('sha256', $enviado['archivo']))
        ->and($enviado['hashArchivo'])->not->toBe(hash('sha256', '<facturaElectronicaCompraVenta/>'))
        ->and($enviado['cuis'])->toBe('CUIS-1')
        ->and($enviado['cufd'])->toBe('CUFD-1')
        ->and($enviado['codigoDocumentoSector'])->toBe(1)
        ->and($enviado['tipoFacturaDocumento'])->toBe(1)
        ->and($enviado['codigoEmision'])->toBe(1);
});

// --- Respuestas del SIN ------------------------------------------------------------

test('RespuestaSiat distingue aceptacion de rechazo sin SoapFault', function () {
    $rechazo = RespuestaSiat::desde((object) ['RespuestaServicioFacturacion' => (object) [
        'transaccion' => false,
        'mensajesList' => [
            (object) ['codigo' => 1001, 'descripcion' => 'HASH INVALIDO'],
            (object) ['codigo' => 1002, 'descripcion' => 'FIRMA INVALIDA'],
        ],
    ]]);

    $unSoloMensaje = RespuestaSiat::desde((object) ['RespuestaServicioFacturacion' => (object) [
        'transaccion' => false,
        'mensajesList' => (object) ['codigo' => 913, 'descripcion' => 'CUIS INVALIDO'],
    ]]);

    $aceptada = RespuestaSiat::desde((object) ['RespuestaServicioFacturacion' => (object) [
        'transaccion' => true, 'codigoRecepcion' => 123, 'codigoEstado' => 908,
    ]]);

    expect($rechazo->aceptada)->toBeFalse()
        ->and($rechazo->motivo())->toBe('[1001] HASH INVALIDO | [1002] FIRMA INVALIDA')
        ->and($unSoloMensaje->motivo())->toBe('[913] CUIS INVALIDO')
        ->and($aceptada->aceptada)->toBeTrue()
        ->and($aceptada->codigoRecepcion)->toBe('123')
        ->and($aceptada->codigoEstado)->toBe('908');
});

test('un catalogo rechazado no pasa por vacio', function () {
    expect(RespuestaSiat::rechazoDeCatalogo((object) ['RespuestaListaParametricas' => (object) [
        'transaccion' => false,
        'mensajesList' => (object) ['codigo' => 995, 'descripcion' => 'PUNTO DE VENTA INEXISTENTE'],
    ]]))->toBe('[995] PUNTO DE VENTA INEXISTENTE')
        ->and(RespuestaSiat::rechazoDeCatalogo((object) ['RespuestaListaParametricas' => (object) ['transaccion' => true]]))
        ->toBeNull();
});

// --- Estados de la verificacion ------------------------------------------------------

test('los codigos de estado del SIN se interpretan bien', function (string $codigo, string $estado, bool $avisa) {
    Queue::fake();
    $factura = facturaParaXml();
    $factura->update(['estado' => Factura::ESTADO_RECIBIDA]);

    $servicio = Mockery::mock(ServicioFacturacion::class);
    $servicio->shouldReceive('verificarEstado')->andReturn(['RespuestaServicioFacturacion' => ['codigoEstado' => $codigo]]);
    $this->mock(FabricaServicios::class, fn ($mock) => $mock->shouldReceive('facturacion')->andReturn($servicio));

    $job = (new VerificarEstadoFactura($factura->id))->withFakeQueueInteractions();
    $job->handle(app(FabricaServicios::class));

    expect($factura->fresh()->estado)->toBe($estado)
        ->and($factura->fresh()->codigo_estado_siat)->toBe($codigo);

    $avisa ? Queue::assertPushed(NotificarWebhook::class) : Queue::assertNotPushed(NotificarWebhook::class);
})->with([
    '908 validada' => ['908', Factura::ESTADO_VALIDADA, true],
    '690 validada' => ['690', Factura::ESTADO_VALIDADA, true],
    '902 rechazada: observada' => ['902', Factura::ESTADO_OBSERVADA, true],
    '901 pendiente: no valida ni observa' => ['901', Factura::ESTADO_RECIBIDA, false],
    '-1 excepcion del SIN: se vuelve a preguntar' => ['-1', Factura::ESTADO_RECIBIDA, false],
    '905 desconocido: observada' => ['905', Factura::ESTADO_OBSERVADA, true],
]);

// --- Motivo de la observacion ----------------------------------------------------

test('una factura rechazada en la recepcion guarda el motivo y lo manda en el webhook', function () {
    Queue::fake();
    $factura = facturaParaXml();
    $factura->update(['xml_firmado' => '<f/>', 'ruta_pdf' => 'ya.pdf']);

    $servicio = Mockery::mock(ServicioFacturacion::class);
    $servicio->shouldReceive('recepcionarFactura')->andReturn((object) ['RespuestaServicioFacturacion' => (object) [
        'transaccion' => false, 'codigoEstado' => 904,
        'mensajesList' => (object) ['codigo' => 1001, 'descripcion' => 'HASH INVALIDO'],
    ]]);
    // Antes de observar se le pregunta al SIN: no la conoce.
    $servicio->shouldReceive('verificarEstado')->once()->andReturn((object) ['RespuestaServicioFacturacion' => (object) [
        'transaccion' => false, 'mensajesList' => (object) ['codigo' => 994, 'descripcion' => 'FACTURA NO EXISTE'],
    ]]);
    $this->mock(FabricaServicios::class, fn ($mock) => $mock->shouldReceive('facturacion')->andReturn($servicio));

    app()->call([new EnviarFacturaAlSiat($factura->id), 'handle']);

    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_OBSERVADA)
        ->and($factura->fresh()->motivo_observacion)->toBe('[1001] HASH INVALIDO');
    Queue::assertPushed(NotificarWebhook::class, fn ($job) => $job->evento === 'factura.observada');
});

test('la verificacion guarda el motivo de la observacion, o el codigo si no hay mensajes', function (array $respuesta, string $motivo) {
    Queue::fake();
    $factura = facturaParaXml();
    $factura->update(['estado' => Factura::ESTADO_RECIBIDA]);

    $servicio = Mockery::mock(ServicioFacturacion::class);
    $servicio->shouldReceive('verificarEstado')->andReturn(['RespuestaServicioFacturacion' => $respuesta]);
    $this->mock(FabricaServicios::class, fn ($mock) => $mock->shouldReceive('facturacion')->andReturn($servicio));

    (new VerificarEstadoFactura($factura->id))->withFakeQueueInteractions()->handle(app(FabricaServicios::class));

    expect($factura->fresh()->motivo_observacion)->toBe($motivo);
})->with([
    'con mensajes' => [['codigoEstado' => '904', 'mensajesList' => ['codigo' => 1037, 'descripcion' => 'NIT INVALIDO']], '[1037] NIT INVALIDO'],
    'sin mensajes' => [['codigoEstado' => '905', 'codigoDescripcion' => 'RECHAZADA'], '[905] RECHAZADA'],
]);

test('la API y el webhook exponen el motivo de la observacion', function () {
    config(['siat.webhooks.permitir_destinos_privados' => true]);
    Http::fake();

    $empresa = Empresa::factory()->create(['webhook_url' => 'http://localhost/hook']);
    $factura = Factura::factory()->for($empresa)->create([
        'estado' => Factura::ESTADO_OBSERVADA, 'motivo_observacion' => '[1001] HASH INVALIDO',
    ]);

    $this->withToken($empresa->emitirTokenApi('T', 'caja')->plainTextToken)
        ->getJson("/api/v1/facturas/{$factura->cuf}")
        ->assertJsonPath('factura.motivo_observacion', '[1001] HASH INVALIDO');

    app()->call([new NotificarWebhook($factura->id, 'factura.observada'), 'handle']);

    Http::assertSent(fn ($request) => $request['motivo'] === '[1001] HASH INVALIDO');
});

// --- Colas -------------------------------------------------------------------------

test('las facturas van a la cola prioritaria y el piloto a la de menor prioridad', function () {
    Queue::fake();

    EnviarFacturaAlSiat::dispatch(1);
    VerificarEstadoFactura::dispatch(1);
    AnularFacturaEnSiat::dispatch(1);
    EnviarPaqueteContingencia::dispatch(1);
    EjecutarCasoPrueba::dispatch(1, 1, now()->toIso8601String());
    NotificarWebhook::dispatch(1, 'factura.validada');

    foreach ([EnviarFacturaAlSiat::class, VerificarEstadoFactura::class, AnularFacturaEnSiat::class, EnviarPaqueteContingencia::class] as $job) {
        Queue::assertPushedOn('facturas', $job);
    }

    Queue::assertPushedOn('piloto', EjecutarCasoPrueba::class);
});

// --- Certificado vencido -----------------------------------------------------------

test('no se firma con un certificado vencido', function () {
    Queue::fake();
    $empresa = Empresa::factory()->enProduccion()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $pv = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);
    Cufd::factory()->create(['punto_venta_id' => $pv->id]);
    Certificado::factory()->for($empresa)->firmable()->create(['vence_el' => now()->subDay()]);

    $venta = [
        'sucursal' => 0, 'punto_venta' => 0, 'metodo_pago' => 1,
        'comprador' => ['tipo_documento' => 1, 'numero_documento' => '1234567', 'razon_social' => 'JUAN PEREZ'],
        'items' => [['codigo_producto_sin' => 83141, 'descripcion' => 'X', 'cantidad' => 1, 'unidad_medida' => 58, 'precio_unitario' => 10]],
    ];

    expect(fn () => app(EmisorFactura::class)->emitir($empresa, $venta))
        ->toThrow(FacturaInvalidaException::class);

    // Ni factura ni numero consumido.
    expect(Factura::count())->toBe(0)->and($pv->fresh()->siguiente_factura)->toBe(1);
});

test('un certificado vale hasta el final del dia en que vence', function () {
    $hoy = Certificado::factory()->make(['vence_el' => now()->toDateString()]);
    $ayer = Certificado::factory()->make(['vence_el' => now()->subDay()->toDateString()]);
    $sinFecha = Certificado::factory()->make(['vence_el' => null]);

    expect($hoy->estaVencido())->toBeFalse()
        ->and($ayer->estaVencido())->toBeTrue()
        ->and($sinFecha->estaVencido())->toBeFalse();
});

// --- Factura publica (enlace firmado) ------------------------------------------------

/**
 * Pide una URL publica de la factura pasando por la app (ruta + query firmada).
 */
function abrirPublica(string $url): TestResponse
{
    return test()->get(parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY));
}

test('la factura publica se abre sin token con su enlace firmado', function () {
    $factura = facturaParaXml();
    $factura->update(['xml_firmado' => '<f/>']);

    abrirPublica($factura->urlPublica())
        ->assertOk()
        ->assertSee('Hoja carta')
        ->assertSee('Ticket de 80 mm')
        ->assertSee($factura->urlPublica('pdf'), false)
        ->assertSee($factura->urlPublica('rollo'), false)
        ->assertSee('Verificar en el SIN');
});

test('sin firma, con firma falsa o con la firma de otra factura responde 404', function () {
    $factura = facturaParaXml();
    $factura->update(['xml_firmado' => '<f/>']);
    $otra = Factura::factory()->create(['xml_firmado' => '<f/>']);

    $this->get("/factura/{$factura->cuf}")->assertNotFound();
    $this->get("/factura/{$factura->cuf}?signature=falsa")->assertNotFound();

    // La firma de una factura no abre otra: no se pueden recorrer cambiando el CUF.
    $firmaAjena = parse_url($otra->urlPublica(), PHP_URL_QUERY);
    $this->get("/factura/{$factura->cuf}?{$firmaAjena}")->assertNotFound();
});

test('el PDF carta y el ticket de 80 mm se descargan desde el enlace publico', function () {
    $factura = facturaParaXml();
    $factura->update(['xml_firmado' => '<f/>']);
    Storage::fake('local');

    abrirPublica($factura->urlPublica('pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $rollo = abrirPublica($factura->urlPublica('rollo'))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    // El mismo ancho del ticket del SIN: 240 puntos.
    expect($rollo->getContent())->toContain('[0.000 0.000 240.000');
});

test('una factura anulada se sigue viendo, marcada como anulada', function () {
    $factura = facturaParaXml();
    $factura->update(['xml_firmado' => '<f/>', 'estado' => Factura::ESTADO_ANULADA]);

    abrirPublica($factura->urlPublica())->assertOk()->assertSee('FACTURA ANULADA');
});

test('la API y el webhook entregan los enlaces publicos', function () {
    config(['siat.webhooks.permitir_destinos_privados' => true]);
    Http::fake();
    $empresa = Empresa::factory()->create(['webhook_url' => 'http://localhost/hook']);
    $factura = Factura::factory()->for($empresa)->create(['xml_firmado' => '<f/>']);

    $this->withToken($empresa->emitirTokenApi('T', 'caja')->plainTextToken)
        ->getJson("/api/v1/facturas/{$factura->cuf}")
        ->assertJsonPath('factura.url_publica', $factura->urlPublica())
        ->assertJsonPath('factura.url_publica_pdf', $factura->urlPublica('pdf'))
        ->assertJsonPath('factura.url_publica_rollo', $factura->urlPublica('rollo'));

    app()->call([new NotificarWebhook($factura->id, 'factura.validada'), 'handle']);

    Http::assertSent(fn ($request) => $request['url_publica'] === $factura->urlPublica());
});

// --- Formato V2 (etapas originales del piloto, por /v2) ------------------------------

test('el calculador V2 deja el IVA dentro del precio: montoTotal y sujeto a IVA sin gift card', function () {
    $totales = app(CalculadorTotales::class)->calcular(
        [['cantidad' => 2, 'precio_unitario' => 50, 'descuento' => 10], ['cantidad' => 1, 'precio_unitario' => 100]],
        descuentoGlobal: 20,
        giftCard: 30,
        version: SiatClient::VERSION_V2,
    );

    // (2 x 50 - 10) + 100 = 190; - 20 de descuento adicional = 170; - 30 de gift card = 140.
    expect($totales)->toMatchArray([
        'subtotal' => 190.0,
        'monto_total' => 170.0,
        'monto_total_sujeto_iva' => 140.0,
        'iva' => 0.0,
    ]);
});

test('la factura V2 lleva montoTotal y cafc, sin iva ni codigoInterno, y el complemento vacio en nil', function () {
    $factura = facturaParaXml();
    $factura->update(['version' => SiatClient::VERSION_V2, 'monto_total' => 170, 'monto_total_sujeto_iva' => 140]);

    $xml = app(ConstructorXml::class)->construir($factura->fresh());
    $cabecera = (new SimpleXMLElement($xml))->cabecera;
    $detalle = (new SimpleXMLElement($xml))->detalle;

    expect(array_keys((array) $cabecera))->toBe([
        'nitEmisor', 'razonSocialEmisor', 'municipio', 'telefono', 'numeroFactura', 'cuf', 'cufd', 'codigoSucursal',
        'direccion', 'codigoPuntoVenta', 'fechaEmision', 'nombreRazonSocial', 'codigoTipoDocumentoIdentidad',
        'numeroDocumento', 'complemento', 'codigoCliente', 'codigoMetodoPago', 'numeroTarjeta', 'montoTotal',
        'montoTotalSujetoIva', 'codigoMoneda', 'tipoCambio', 'montoTotalMoneda', 'montoGiftCard', 'descuentoAdicional',
        'codigoExcepcion', 'cafc', 'leyenda', 'usuario', 'codigoDocumentoSector',
    ])
        ->and((string) $cabecera->montoTotal)->toBe('170.00')
        ->and((string) $cabecera->montoTotalSujetoIva)->toBe('140.00')
        ->and($xml)->toContain('<complemento xsi:nil="true"/>')
        ->and($xml)->toContain('<cafc xsi:nil="true"/>')
        ->and($xml)->not->toContain('<iva>')
        ->and($xml)->not->toContain('<codigoInterno>')
        ->and(array_keys((array) $detalle))->toContain('precioUnitario')
        ->and(array_keys((array) $detalle))->not->toContain('precioNetoUnitario')
        ->and((string) $detalle->cantidad)->toMatch('/^\d+\.\d{4}$/');
});
