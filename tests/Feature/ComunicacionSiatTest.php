<?php

use App\Models\Empresa;
use App\Models\Factura;
use App\Models\LogSiat;
use App\Models\User;
use App\Services\Contingencia\ArmadorPaquete;
use App\Services\Panel\ComparadorLineas;
use App\Services\Panel\LecturaLogSiat;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Sobre SOAP de una recepcion con la factura en gzip + base64, como viaja.
 */
function sobreRecepcion(string $documento, ?string $hash = null, string $cuis = 'CUIS-1'): string
{
    $gzip = gzencode($documento, 9);
    $hash ??= hash('sha256', $gzip);

    return '<?xml version="1.0" encoding="UTF-8"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="https://siat.impuestos.gob.bo/">'
        .'<SOAP-ENV:Body><ns1:recepcionFactura><SolicitudServicioRecepcionFactura>'
        ."<codigoAmbiente>2</codigoAmbiente><cuis>{$cuis}</cuis><archivo>".base64_encode($gzip)."</archivo><hashArchivo>{$hash}</hashArchivo>"
        .'</SolicitudServicioRecepcionFactura></ns1:recepcionFactura></SOAP-ENV:Body></SOAP-ENV:Envelope>';
}

/**
 * Respuesta del SIN con los campos indicados dentro de RespuestaServicioFacturacion.
 */
function respuestaSin(string $cuerpo): string
{
    return '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><ns2:recepcionFacturaResponse xmlns:ns2="https://siat.impuestos.gob.bo/">'
        ."<RespuestaServicioFacturacion>{$cuerpo}</RespuestaServicioFacturacion></ns2:recepcionFacturaResponse></soap:Body></soap:Envelope>";
}

function facturaXml(string $numero, string $cuf = 'CUF-1'): string
{
    return "<facturaElectronicaCompraVenta><cabecera><nitEmisor>7633685015</nitEmisor><numeroFactura>{$numero}</numeroFactura><cuf>{$cuf}</cuf></cabecera></facturaElectronicaCompraVenta>";
}

/**
 * @param  array<string, mixed>  $datos
 */
function llamadaSiat(array $datos = []): LogSiat
{
    return LogSiat::create($datos + [
        'empresa_id' => Empresa::factory()->create()->id,
        'servicio' => 'ServicioFacturacionCompraVenta',
        'operacion' => 'recepcionFactura',
        'xml_enviado' => sobreRecepcion(facturaXml('1')),
        'xml_recibido' => respuestaSin('<codigoDescripcion>VALIDADA</codigoDescripcion><codigoEstado>908</codigoEstado><codigoRecepcion>REC-1</codigoRecepcion><transaccion>true</transaccion>'),
        'duracion_ms' => 2100,
        'exitoso' => true,
        'created_at' => now(),
    ]);
}

function rechazoMenosUno(): string
{
    return respuestaSin('<codigoDescripcion>Error inesperado: Excepcion al registrar la factura</codigoDescripcion><codigoEstado>-1</codigoEstado><transaccion>false</transaccion>');
}

test('distingue aceptada, rechazada por el SIN y sin respuesta', function () {
    expect((new LecturaLogSiat(llamadaSiat()))->resultado())->toBe(LecturaLogSiat::ACEPTADA)
        ->and((new LecturaLogSiat(llamadaSiat(['xml_recibido' => rechazoMenosUno()])))->resultado())->toBe(LecturaLogSiat::RECHAZADA)
        ->and((new LecturaLogSiat(llamadaSiat(['exitoso' => false, 'xml_recibido' => null, 'mensaje_error' => 'timeout'])))->resultado())->toBe(LecturaLogSiat::SIN_RESPUESTA);
});

test('el listado cuenta las ultimas 24 h, destaca el ultimo problema y filtra por resultado', function () {
    $this->actingAs(User::factory()->create());
    llamadaSiat();
    llamadaSiat(['xml_recibido' => rechazoMenosUno()]);
    llamadaSiat(['exitoso' => false, 'xml_recibido' => null, 'mensaje_error' => 'Error Fetching http headers', 'operacion' => 'cufd']);
    llamadaSiat(['created_at' => now()->subDays(3)]);

    $this->get(route('admin.comunicacion.index'))
        ->assertOk()
        ->assertSee('Ultimo problema · cufd')
        ->assertSee('Error Fetching http headers')
        ->assertSee('33.3%')
        ->assertSee('[-1] Error inesperado: Excepcion al registrar la factura');

    $soloRechazadas = $this->get(route('admin.comunicacion.index', ['resultado' => LecturaLogSiat::RECHAZADA]));

    expect($soloRechazadas->viewData('llamadas')->total())->toBe(1);
    $soloRechazadas->assertDontSee('Error Fetching http headers</td>', false);
});

test('busca en el XML de la solicitud y la respuesta', function () {
    $this->actingAs(User::factory()->create());
    llamadaSiat(['xml_enviado' => sobreRecepcion(facturaXml('1'), cuis: 'CUIS-BUSCADO')]);
    llamadaSiat();

    expect($this->get(route('admin.comunicacion.index', ['buscar' => 'CUIS-BUSCADO']))->viewData('llamadas')->total())->toBe(1);
});

test('el detalle abre la factura del adjunto, verifica el hash y explica el -1', function () {
    $this->actingAs(User::factory()->create());
    $factura = Factura::factory()->create(['cuf' => 'CUF-DEL-ADJUNTO']);
    $log = llamadaSiat(['xml_enviado' => sobreRecepcion(facturaXml('130', 'CUF-DEL-ADJUNTO')), 'xml_recibido' => rechazoMenosUno()]);

    $this->get(route('admin.comunicacion.show', $log))
        ->assertOk()
        ->assertSee('7633685015')
        ->assertSee('El hash del adjunto coincide')
        ->assertSee('transaccion = false')
        ->assertSee('Estado -1 sin mensajes')
        ->assertSee(route('admin.facturas.show', $factura));
});

test('un hash que no corresponde al adjunto se marca', function () {
    $this->actingAs(User::factory()->create());
    $log = llamadaSiat(['xml_enviado' => sobreRecepcion(facturaXml('1'), hash: str_repeat('0', 64))]);

    $this->get(route('admin.comunicacion.show', $log))->assertSee('El hash del adjunto NO coincide');
});

test('una rechazada se compara sola con la ultima aceptada y muestra solo lo que cambio', function () {
    $this->actingAs(User::factory()->create());
    $empresa = Empresa::factory()->create();
    $aceptada = llamadaSiat(['empresa_id' => $empresa->id, 'xml_enviado' => sobreRecepcion(facturaXml('195'), cuis: 'CUIS-VIEJO')]);
    llamadaSiat(['xml_recibido' => rechazoMenosUno()]); // de otra empresa: no cuenta
    $rechazada = llamadaSiat(['empresa_id' => $empresa->id, 'xml_enviado' => sobreRecepcion(facturaXml('200'), cuis: 'CUIS-NUEVO'), 'xml_recibido' => rechazoMenosUno()]);

    $respuesta = $this->get(route('admin.comunicacion.show', $rechazada))->assertOk();

    expect($respuesta->viewData('comparacion')['log']->is($aceptada))->toBeTrue();
    $respuesta->assertSee('− cuis: CUIS-VIEJO')->assertSee('+ cuis: CUIS-NUEVO');

    // Las lineas del documento van indentadas: se busca la marca y la etiqueta.
    expect($respuesta->getContent())
        ->toMatch('/d-quitada">− +&lt;numeroFactura&gt;195/')
        ->toMatch('/d-agregada">\+ +&lt;numeroFactura&gt;200/')
        ->not->toMatch('/d-quitada">− +&lt;nitEmisor/');
});

test('una aceptada no se compara sola, pero se puede elegir contra cual', function () {
    $this->actingAs(User::factory()->create());
    $otra = llamadaSiat();
    $log = llamadaSiat();

    expect($this->get(route('admin.comunicacion.show', $log))->viewData('comparacion'))->toBeNull()
        ->and($this->get(route('admin.comunicacion.show', [$log, 'comparar' => $otra->id]))->viewData('comparacion')['log']->is($otra))->toBeTrue();
});

test('abre las facturas de un paquete de contingencia (TAR)', function () {
    $tar = app(ArmadorPaquete::class)->tar(['A.xml' => facturaXml('1', 'A'), 'B.xml' => facturaXml('2', 'B')]);
    $log = llamadaSiat(['operacion' => 'recepcionPaqueteFactura', 'xml_enviado' => sobreRecepcion($tar)]);

    $documentos = (new LecturaLogSiat($log))->documentos();

    expect(array_column($documentos, 'nombre'))->toBe(['A.xml', 'B.xml'])
        ->and($documentos[1]['xml'])->toContain('<cuf>B</cuf>');
});

test('el XML se muestra escapado aunque traiga codigo', function () {
    $resaltado = (string) LecturaLogSiat::resaltar('<a x="1"><script>alert(1)</script></a>');

    expect($resaltado)->not->toContain('<script>')
        ->and($resaltado)->toContain('<span class="x-tag">script</span>')
        ->and($resaltado)->toContain('<span class="x-attr">x</span>');
});

test('el comparador marca lineas quitadas y agregadas y resume las iguales', function () {
    $antes = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];
    $despues = ['a', 'b', 'c', 'd', 'e', 'F', 'g', 'h'];

    expect(app(ComparadorLineas::class)->comparar($antes, $despues))->toBe([
        ['tipo' => 'salto', 'texto' => '3 lineas iguales'],
        ['tipo' => 'igual', 'texto' => 'd'],
        ['tipo' => 'igual', 'texto' => 'e'],
        ['tipo' => 'quitada', 'texto' => 'f'],
        ['tipo' => 'agregada', 'texto' => 'F'],
        ['tipo' => 'igual', 'texto' => 'g'],
        ['tipo' => 'igual', 'texto' => 'h'],
    ]);
});

test('la pantalla exige sesion', function () {
    $this->get(route('admin.comunicacion.index'))->assertRedirect(route('login'));
});
