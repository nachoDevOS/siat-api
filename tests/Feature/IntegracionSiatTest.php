<?php

use App\Exceptions\SiatException;
use App\Http\Requests\EmitirFacturaRequest;
use App\Jobs\EnviarFacturaAlSiat;
use App\Jobs\NotificarWebhook;
use App\Jobs\VerificarEstadoFactura;
use App\Models\Certificado;
use App\Models\Cufd;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Services\Factura\EmisorFactura;
use App\Services\Factura\ValidadorFactura;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\ServicioFacturacion;
use App\Services\Siat\SiatClient;
use App\Services\Webhooks\DestinoWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

/**
 * Empresa en produccion con PV 0, CUFD vigente y certificado que firma.
 */
function empresaQueFirma(): Empresa
{
    $empresa = Empresa::factory()->enProduccion()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $pv = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);
    Cufd::factory()->create(['punto_venta_id' => $pv->id]);
    Certificado::factory()->for($empresa)->firmable()->create();

    return $empresa;
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function ventaMinima(array $extra = []): array
{
    return array_replace_recursive([
        'sucursal' => 0,
        'punto_venta' => 0,
        'comprador' => ['tipo_documento' => 1, 'numero_documento' => '1234567', 'razon_social' => 'JUAN PEREZ'],
        'metodo_pago' => 1,
        'items' => [['codigo_producto_sin' => 83141, 'descripcion' => 'X', 'cantidad' => 1, 'unidad_medida' => 58, 'precio_unitario' => 10]],
    ], $extra);
}

/**
 * Mock del servicio de facturacion entregado por la fabrica.
 */
function servicioFacturacionFalso(): ServicioFacturacion
{
    $facturacion = Mockery::mock(ServicioFacturacion::class);
    test()->mock(FabricaServicios::class, fn ($mock) => $mock->shouldReceive('facturacion')->andReturn($facturacion));

    return $facturacion;
}

// --- 7: moneda --------------------------------------------------------------

test('solo se factura en bolivianos con tipo de cambio 1', function (array $extra, bool $pasa) {
    $request = new EmitirFacturaRequest;

    expect(Validator::make(ventaMinima($extra), $request->rules(), $request->messages())->passes())->toBe($pasa);
})->with([
    'sin moneda' => [[], true],
    'bolivianos' => [['moneda' => 1, 'tipo_cambio' => 1], true],
    'dolares' => [['moneda' => 2, 'tipo_cambio' => 6.96], false],
    'bolivianos con otro cambio' => [['moneda' => 1, 'tipo_cambio' => 6.96], false],
]);

// --- 9: codigoExcepcion -------------------------------------------------------

test('la excepcion de NIT solo se acepta con documento NIT', function (int $tipoDocumento, bool $error) {
    $errores = app(ValidadorFactura::class)->validar(ventaMinima([
        'codigo_excepcion' => 1,
        'comprador' => ['tipo_documento' => $tipoDocumento],
    ]));

    expect(str_contains(implode(' ', $errores), 'solo aplica cuando el documento del comprador es NIT'))->toBe($error);
})->with([
    'NIT' => [5, false],
    'CI' => [1, true],
]);

test('el XML lleva codigoExcepcion 0 por defecto y 1 cuando se pide', function (array $extra, string $esperado) {
    Queue::fake();

    $factura = app(EmisorFactura::class)->emitir(empresaQueFirma(), ventaMinima($extra));

    expect($factura->xml_firmado)->toContain("<codigoExcepcion>{$esperado}</codigoExcepcion>")
        ->and($factura->codigo_excepcion)->toBe((int) $esperado);
})->with([
    'normal' => [[], '0'],
    'NIT invalido' => [['codigo_excepcion' => 1, 'comprador' => ['tipo_documento' => 5, 'numero_documento' => '999']], '1'],
]);

// --- 17: validada en la misma recepcion --------------------------------------

test('si la recepcion ya viene validada (908) no se vuelve a preguntar', function () {
    Queue::fake();
    $factura = Factura::factory()->create(['xml_firmado' => '<f/>', 'ruta_pdf' => 'ya.pdf']);

    servicioFacturacionFalso()->shouldReceive('recepcionarFactura')->once()->andReturn((object) [
        'RespuestaServicioFacturacion' => (object) ['transaccion' => true, 'codigoRecepcion' => 'REC-1', 'codigoEstado' => 908],
    ]);

    app()->call([new EnviarFacturaAlSiat($factura->id), 'handle']);

    $factura->refresh();
    expect($factura->estado)->toBe(Factura::ESTADO_VALIDADA)
        ->and($factura->validada_en)->not->toBeNull()
        ->and($factura->codigo_recepcion)->toBe('REC-1');
    Queue::assertNotPushed(VerificarEstadoFactura::class);
    Queue::assertPushed(NotificarWebhook::class, fn ($job) => $job->evento === 'factura.validada');
});

test('si la recepcion no la valida todavia queda RECIBIDA y se verifica despues', function () {
    Queue::fake();
    $factura = Factura::factory()->create(['xml_firmado' => '<f/>', 'ruta_pdf' => 'ya.pdf']);

    servicioFacturacionFalso()->shouldReceive('recepcionarFactura')->once()->andReturn((object) [
        'RespuestaServicioFacturacion' => (object) ['transaccion' => true, 'codigoRecepcion' => 'REC-1', 'codigoEstado' => 901],
    ]);

    app()->call([new EnviarFacturaAlSiat($factura->id), 'handle']);

    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_RECIBIDA);
    Queue::assertPushed(VerificarEstadoFactura::class);
});

// --- 13: verificacion en el ultimo intento -----------------------------------

test('la verificacion no se reencola en su ultimo intento', function (int $intento, bool $reencola) {
    $factura = Factura::factory()->create(['estado' => Factura::ESTADO_RECIBIDA]);
    servicioFacturacionFalso()->shouldReceive('verificarEstado')->andThrow(new SiatException('SIAT caido'));

    $job = Mockery::mock(VerificarEstadoFactura::class.'[attempts,release]', [$factura->id]);
    $job->shouldReceive('attempts')->andReturn($intento);
    $reencola
        ? $job->shouldReceive('release')->once()
        : $job->shouldNotReceive('release');

    app()->call([$job, 'handle']);

    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_RECIBIDA);
})->with([
    'intermedio' => [2, true],
    'ultimo' => [5, false],
]);

// --- 14: secreto de webhook por empresa --------------------------------------

test('cada empresa firma sus webhooks con su propio secreto', function () {
    config(['siat.webhooks.secreto' => 'secreto-global', 'siat.webhooks.permitir_destinos_privados' => true]);
    Http::fake();

    $empresa = Empresa::factory()->create(['webhook_url' => 'http://localhost/hook']);
    $otra = Empresa::factory()->create();
    $factura = Factura::factory()->for($empresa)->create();

    expect($empresa->secretoWebhook())->not->toBe($otra->secretoWebhook())
        ->and($empresa->secretoWebhook())->not->toBe('secreto-global');

    app()->call([new NotificarWebhook($factura->id, 'factura.validada'), 'handle']);

    Http::assertSent(fn (Request $request) => $request->header('X-Siat-Signature')[0]
        === 'sha256='.hash_hmac('sha256', $request->body(), $empresa->secretoWebhook()));
});

test('sin secreto global no hay secreto por empresa', function () {
    config(['siat.webhooks.secreto' => null]);

    expect(Empresa::factory()->create()->secretoWebhook())->toBeNull();
});

// --- 15: IP fijada contra rebinding -------------------------------------------

test('el destino del webhook devuelve la IP validada para fijarla', function () {
    config(['siat.webhooks.permitir_destinos_privados' => false]);

    $destino = Mockery::mock(DestinoWebhook::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $destino->shouldReceive('resolver')->with('cliente.example')->andReturn(['93.184.216.34']);

    expect($destino->evaluar('https://cliente.example/hook'))
        ->toBe(['motivo' => null, 'fijar' => 'cliente.example:443:93.184.216.34']);
});

test('una IP interna sigue rechazada y no se fija nada', function () {
    config(['siat.webhooks.permitir_destinos_privados' => false]);

    $destino = Mockery::mock(DestinoWebhook::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $destino->shouldReceive('resolver')->andReturn(['10.0.0.5']);

    expect($destino->evaluar('https://interno.example/hook'))
        ->toMatchArray(['fijar' => null])
        ->and($destino->evaluar('https://interno.example/hook')['motivo'])->toContain('direccion interna');
});

// --- 16: timeout tambien al bajar el WSDL --------------------------------------

test('la descarga del WSDL corre con el timeout del SIAT y lo restaura despues', function () {
    config(['siat.timeout' => 7, 'siat.urls.2' => 'https://siat.example']);
    ini_set('default_socket_timeout', '60');
    $timeoutVisto = null;

    $cliente = Mockery::mock(SiatClient::class, [Empresa::factory()->make(['codigo_ambiente' => 2])])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $cliente->shouldReceive('crearSoapClient')->andReturnUsing(function () use (&$timeoutVisto) {
        $timeoutVisto = ini_get('default_socket_timeout');

        throw new SoapFault('HTTP', 'Could not connect to host');
    });

    expect(fn () => $cliente->paraServicio('codigos'))->toThrow(SiatException::class)
        ->and($timeoutVisto)->toBe('7')
        ->and(ini_get('default_socket_timeout'))->toBe('60');
});
