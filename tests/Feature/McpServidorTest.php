<?php

use App\Mcp\Servers\SiatServer;
use App\Mcp\Tools\AnularFactura;
use App\Mcp\Tools\ConsultarFactura;
use App\Mcp\Tools\EmitirFactura;
use App\Mcp\Tools\EstadoIntegracion;
use App\Mcp\Tools\ListarFacturas;
use App\Models\Catalogo;
use App\Models\Certificado;
use App\Models\Cufd;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Deja la empresa del token en la peticion, como lo hace AutenticarToken
 * delante del servidor MCP.
 */
function agenteCon(string $perfil = 'administracion'): Empresa
{
    $empresa = Empresa::factory()->enProduccion()->create();
    $pv = PuntoVenta::factory()->for(Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]))->create(['codigo_punto_venta' => 0]);
    Cufd::factory()->create(['punto_venta_id' => $pv->id]);
    Certificado::factory()->for($empresa)->firmable()->create();

    $empresa->withAccessToken($empresa->emitirTokenApi('Agente', $perfil)->accessToken);
    request()->attributes->set('empresa', $empresa);

    return $empresa;
}

test('el agente consulta el estado y emite una factura con referencia', function () {
    Queue::fake();
    agenteCon();

    SiatServer::tool(EstadoIntegracion::class)->assertOk()->assertSee('"ambiente": "PRODUCCION"');

    $venta = [
        'sucursal' => 0, 'punto_venta' => 0, 'referencia_externa' => 'CHAT-1', 'metodo_pago' => 1,
        'comprador' => ['tipo_documento' => 1, 'numero_documento' => '1234567', 'razon_social' => 'JUAN PEREZ'],
        'items' => [['codigo_producto_sin' => 83141, 'descripcion' => 'X', 'cantidad' => 1, 'unidad_medida' => 58, 'precio_unitario' => 10]],
    ];

    SiatServer::tool(EmitirFactura::class, ['venta' => $venta])->assertOk()->assertSee('"referencia_externa": "CHAT-1"');
    SiatServer::tool(EmitirFactura::class, ['venta' => $venta])->assertOk();

    expect(Factura::count())->toBe(1);

    $cuf = Factura::sole()->cuf;
    SiatServer::tool(ConsultarFactura::class, ['cuf' => $cuf])->assertOk()->assertSee($cuf);
    SiatServer::tool(ListarFacturas::class)->assertOk()->assertSee($cuf);
});

test('el agente recibe los errores de negocio como error, sin excepcion', function () {
    agenteCon();

    SiatServer::tool(EmitirFactura::class, ['venta' => ['sucursal' => 0]])->assertHasErrors();
    SiatServer::tool(ConsultarFactura::class, ['cuf' => 'NO-EXISTE'])->assertHasErrors(['No existe una factura de la empresa con ese CUF.']);
});

test('cada herramienta exige el permiso de su endpoint: una caja no anula', function () {
    $empresa = agenteCon('caja');
    Catalogo::factory()->create(['tipo' => 'motivos_anulacion', 'codigo_clasificador' => '1', 'descripcion' => 'X']);
    $factura = Factura::factory()->validada()->create(['empresa_id' => $empresa->id]);

    SiatServer::tool(AnularFactura::class, ['cuf' => $factura->cuf, 'motivo' => 1])
        ->assertHasErrors(["El token no tiene el permiso 'facturas:anular'."]);

    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_VALIDADA);
});

test('el servidor MCP por HTTP pide el token de la empresa', function () {
    $empresa = Empresa::factory()->enProduccion()->create();
    $mensaje = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];

    $this->postJson('/mcp', $mensaje)->assertUnauthorized();

    $herramientas = $this->withToken($empresa->emitirTokenApi('Agente', 'consulta')->plainTextToken)
        ->postJson('/mcp', $mensaje)
        ->assertSuccessful()
        ->json('result.tools.*.name');

    expect($herramientas)->toContain('estado-integracion', 'emitir-factura', 'emitir-nota-credito-debito');
});
