<?php

use App\Models\ActividadEconomica;
use App\Models\Catalogo;
use App\Models\Empresa;
use App\Models\LeyendaFactura;
use App\Models\ProductoServicio;
use App\Services\Catalogos\SincronizadorEmpresa;
use App\Services\Catalogos\SincronizadorGlobal;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\ServicioSincronizacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/**
 * Sustituye la fabrica por un doble que siempre devuelve el servicio dado.
 * Antes estos sincronizadores construian el SiatClient a mano y no habia forma
 * de probarlos sin el SIN al otro lado (deuda #7 del analisis).
 */
function fabricaDeSincronizacion(ServicioSincronizacion $servicio): void
{
    test()->mock(FabricaServicios::class, function ($mock) use ($servicio) {
        $mock->shouldReceive('sincronizacion')->andReturn($servicio);
    });
}

/**
 * Arma una respuesta SOAP con la forma que devuelve el SIN: un objeto con la
 * lista adentro. Las claves reales quedan pendientes de verificar contra el
 * WSDL vigente; aca solo se prueba el camino de guardado.
 */
function respuestaConLista(string $clave, array $items): object
{
    return (object) [
        'RespuestaListaParametricas' => (object) [
            $clave => array_map(fn (array $fila) => (object) $fila, $items),
        ],
    ];
}

test('las parametricas globales se guardan en catalogos', function () {
    $empresa = Empresa::factory()->create();

    $servicio = Mockery::mock(ServicioSincronizacion::class);
    $servicio->shouldReceive('parametrica')->andReturn(respuestaConLista('listaCodigos', [
        ['codigoClasificador' => '57', 'descripcion' => 'UNIDAD (BIENES)'],
        ['codigoClasificador' => '58', 'descripcion' => 'PAQUETE'],
    ]));
    fabricaDeSincronizacion($servicio);

    $resumen = app(SincronizadorGlobal::class)->sincronizarTodo($empresa, 'CUIS-1');

    expect($resumen['unidades_medida'])->toBe(2);
    expect(Catalogo::where('tipo', 'unidades_medida')->count())->toBe(2);
});

test('sincronizar globales invalida la cache de catalogos', function () {
    $empresa = Empresa::factory()->create();
    Cache::put('siat.catalogos', ['viejo'], 3600);

    $servicio = Mockery::mock(ServicioSincronizacion::class);
    $servicio->shouldReceive('parametrica')->andReturn(respuestaConLista('listaCodigos', []));
    fabricaDeSincronizacion($servicio);

    app(SincronizadorGlobal::class)->sincronizarTodo($empresa, 'CUIS-1');

    expect(Cache::has('siat.catalogos'))->toBeFalse();
});

test('un solo elemento en la respuesta tambien se guarda', function () {
    $empresa = Empresa::factory()->create();

    // SOAP entrega un unico elemento como objeto suelto, no como arreglo.
    $servicio = Mockery::mock(ServicioSincronizacion::class);
    $servicio->shouldReceive('parametrica')->andReturn((object) [
        'RespuestaListaParametricas' => (object) [
            'listaCodigos' => (object) ['codigoClasificador' => '1', 'descripcion' => 'BOLIVIANO'],
        ],
    ]);
    fabricaDeSincronizacion($servicio);

    $resumen = app(SincronizadorGlobal::class)->sincronizarTodo($empresa, 'CUIS-1');

    expect($resumen['tipos_moneda'])->toBe(1);
});

test('los catalogos por empresa quedan atados a su empresa_id', function () {
    $empresa = Empresa::factory()->create();

    $servicio = Mockery::mock(ServicioSincronizacion::class);
    $servicio->shouldReceive('listaActividades')->andReturn(respuestaConLista('listaActividades', [
        ['codigoCaeb' => '620000', 'descripcion' => 'PROGRAMACION', 'tipoActividad' => 'S'],
    ]));
    $servicio->shouldReceive('listaProductosServicios')->andReturn(respuestaConLista('listaProductos', [
        ['codigoActividad' => '620000', 'codigoProducto' => '99100', 'descripcionProducto' => 'SERVICIO'],
    ]));
    $servicio->shouldReceive('listaLeyendas')->andReturn(respuestaConLista('listaLeyendas', [
        ['codigoActividad' => '620000', 'descripcionLeyenda' => 'Ley N 453'],
    ]));
    fabricaDeSincronizacion($servicio);

    $resumen = app(SincronizadorEmpresa::class)->sincronizarTodo($empresa, 'CUIS-1');

    expect($resumen)->toBe(['actividades' => 1, 'productos' => 1, 'leyendas' => 1]);
    expect(ActividadEconomica::where('empresa_id', $empresa->id)->count())->toBe(1);
    expect(ProductoServicio::where('empresa_id', $empresa->id)->count())->toBe(1);
    expect(LeyendaFactura::where('empresa_id', $empresa->id)->count())->toBe(1);
});

test('resincronizar no duplica actividades ni leyendas', function () {
    $empresa = Empresa::factory()->create();

    $servicio = Mockery::mock(ServicioSincronizacion::class);
    $servicio->shouldReceive('listaActividades')->andReturn(respuestaConLista('listaActividades', [
        ['codigoCaeb' => '620000', 'descripcion' => 'PROGRAMACION', 'tipoActividad' => 'S'],
    ]));
    $servicio->shouldReceive('listaProductosServicios')->andReturn(respuestaConLista('listaProductos', []));
    $servicio->shouldReceive('listaLeyendas')->andReturn(respuestaConLista('listaLeyendas', [
        ['codigoActividad' => '620000', 'descripcionLeyenda' => 'Ley N 453'],
    ]));
    fabricaDeSincronizacion($servicio);

    app(SincronizadorEmpresa::class)->sincronizarTodo($empresa, 'CUIS-1');
    app(SincronizadorEmpresa::class)->sincronizarTodo($empresa, 'CUIS-1');

    expect(ActividadEconomica::where('empresa_id', $empresa->id)->count())->toBe(1);
    expect(LeyendaFactura::where('empresa_id', $empresa->id)->count())->toBe(1);
});
