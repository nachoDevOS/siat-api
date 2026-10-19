<?php

use App\Http\Controllers\Api\V1\CatalogoController;
use App\Http\Controllers\Api\V1\CertificadoController;
use App\Http\Controllers\Api\V1\CodigoController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EstadoController;
use App\Http\Controllers\Api\V1\FacturaController;
use App\Http\Controllers\Api\V1\MasivaController;
use App\Http\Controllers\Api\V1\NitController;
use App\Http\Controllers\Api\V1\NotaCreditoDebitoController;
use App\Http\Controllers\Api\V1\PuntoVentaController;
use App\Http\Controllers\Api\V1\SucursalController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API REST v1 — la consumen los sistemas de venta de los clientes.
|--------------------------------------------------------------------------
|
| Toda ruta pasa por:
|   siat.registro  -> deja la llamada en logs_api (tambien los rechazos)
|   siat.token     -> identifica la empresa por su token de Sanctum (Bearer)
|   siat.rate      -> limita peticiones por empresa
|
| Y cada una exige su permiso con siat.permiso:{permiso}. Los permisos y los
| perfiles que los agrupan estan en config('siat.api').
|
| Emitir facturas exige ademas empresa en PRODUCCION (siat.produccion) e
| idempotencia (siat.idempotencia).
*/

Route::prefix('v1')->middleware(['siat.registro', 'siat.token', 'siat.rate'])->group(function () {

    // Salud de la integracion.
    Route::get('estado', EstadoController::class)
        ->middleware('siat.permiso:estado:leer');

    // Catalogos para armar la venta.
    Route::get('catalogos/{tipo}', [CatalogoController::class, 'show'])
        ->middleware('siat.permiso:catalogos:leer');

    // Estructura: sucursales y puntos de venta.
    Route::get('sucursales', [SucursalController::class, 'index'])
        ->middleware('siat.permiso:estructura:leer');
    Route::get('puntos-venta', [PuntoVentaController::class, 'index'])
        ->middleware('siat.permiso:estructura:leer');
    Route::post('puntos-venta', [PuntoVentaController::class, 'store'])
        ->middleware('siat.permiso:estructura:gestionar');
    // Alta en el SIN: IRREVERSIBLE (el SIN no borra puntos de venta).
    Route::post('puntos-venta/registrar-siat', [PuntoVentaController::class, 'registrarEnSiat'])
        ->middleware('siat.permiso:estructura:gestionar');

    // Certificado digital: el cliente lo renueva solo.
    Route::get('certificado', [CertificadoController::class, 'show'])
        ->middleware('siat.permiso:estructura:leer');
    Route::post('certificado', [CertificadoController::class, 'store'])
        ->middleware('siat.permiso:estructura:gestionar');

    // Codigos del SIN: la API los maneja sola; aca solo se ven sus vigencias
    // y, en una emergencia, se fuerza un CUFD nuevo.
    Route::get('puntos-venta/{sucursal}/{puntoVenta}/codigos', [CodigoController::class, 'show'])
        ->whereNumber(['sucursal', 'puntoVenta'])
        ->middleware('siat.permiso:codigos:leer');
    Route::post('puntos-venta/{sucursal}/{puntoVenta}/cufd', [CodigoController::class, 'renovarCufd'])
        ->whereNumber(['sucursal', 'puntoVenta'])
        ->middleware('siat.permiso:codigos:renovar');

    // Verificacion de NIT ante el SIN, para decidir el codigo_excepcion.
    Route::get('nit/{nit}/verificar', [NitController::class, 'verificar'])
        ->middleware('siat.permiso:facturas:emitir');

    // Consulta y descarga de facturas (no exige estar en produccion).
    Route::middleware('siat.permiso:facturas:leer')->group(function () {
        Route::get('facturas', [FacturaController::class, 'index']);
        Route::get('facturas/{cuf}', [FacturaController::class, 'show']);
        Route::get('facturas/{cuf}/pdf', [FacturaController::class, 'pdf']);
        Route::get('facturas/{cuf}/xml', [FacturaController::class, 'xml']);
        Route::get('facturas/{cuf}/notas', [NotaCreditoDebitoController::class, 'index']);
        Route::get('dashboard', DashboardController::class);
    });

    Route::post('facturas/{cuf}/anular', [FacturaController::class, 'anular'])
        ->middleware('siat.permiso:facturas:anular');
    Route::post('facturas/{cuf}/revertir-anulacion', [FacturaController::class, 'revertirAnulacion'])
        ->middleware('siat.permiso:facturas:anular');
    Route::post('facturas/{cuf}/anulacion-administrativa', [FacturaController::class, 'anularAdministrativamente'])
        ->middleware('siat.permiso:facturas:anular');

    // Nota de credito-debito (devolucion). Pide el mismo permiso que anular:
    // las dos le devuelven plata al comprador, asi que un token de caja no
    // puede hacerlas. Y como la emision, exige produccion e idempotencia.
    Route::post('facturas/{cuf}/notas', [NotaCreditoDebitoController::class, 'store'])
        ->middleware(['siat.permiso:facturas:anular', 'siat.produccion', 'siat.idempotencia']);

    // Emision masiva: lote por punto de venta. Abrir y cerrar exigen lo mismo
    // que emitir; consultar, leer facturas.
    Route::get('masiva/{sucursal}/{puntoVenta}', [MasivaController::class, 'show'])
        ->whereNumber(['sucursal', 'puntoVenta'])
        ->middleware('siat.permiso:facturas:leer');
    Route::post('masiva/{sucursal}/{puntoVenta}/abrir', [MasivaController::class, 'abrir'])
        ->whereNumber(['sucursal', 'puntoVenta'])
        ->middleware(['siat.permiso:facturas:emitir', 'siat.produccion']);
    Route::post('masiva/{sucursal}/{puntoVenta}/cerrar', [MasivaController::class, 'cerrar'])
        ->whereNumber(['sucursal', 'puntoVenta'])
        ->middleware(['siat.permiso:facturas:emitir', 'siat.produccion']);

    // Destinos de webhook administrados por el cliente.
    Route::middleware('siat.permiso:webhooks:gestionar')->group(function () {
        Route::get('webhooks', [WebhookController::class, 'index']);
        Route::post('webhooks', [WebhookController::class, 'store']);
        Route::get('webhooks/{id}', [WebhookController::class, 'show'])->whereNumber('id');
        Route::put('webhooks/{id}', [WebhookController::class, 'update'])->whereNumber('id');
        Route::delete('webhooks/{id}', [WebhookController::class, 'destroy'])->whereNumber('id');
        Route::post('webhooks/{id}/rotar-secreto', [WebhookController::class, 'rotarSecreto'])->whereNumber('id');
        Route::get('webhooks/{id}/entregas', [WebhookController::class, 'entregas'])->whereNumber('id');
        Route::post('webhooks/entregas/{uuid}/reenviar', [WebhookController::class, 'reenviar'])->whereUuid('uuid');
    });

    // Emision: solo empresas en produccion y con idempotencia.
    Route::post('facturas', [FacturaController::class, 'store'])
        ->middleware(['siat.permiso:facturas:emitir', 'siat.produccion', 'siat.idempotencia']);
    // Hasta 20 ventas por peticion; la idempotencia va por venta
    // (referencia_externa obligatoria en cada una).
    Route::post('facturas/lote', [FacturaController::class, 'lote'])
        ->middleware(['siat.permiso:facturas:emitir', 'siat.produccion']);
});
