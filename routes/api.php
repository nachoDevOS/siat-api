<?php

use App\Http\Controllers\Api\V1\CatalogoController;
use App\Http\Controllers\Api\V1\CodigoController;
use App\Http\Controllers\Api\V1\EstadoController;
use App\Http\Controllers\Api\V1\FacturaController;
use App\Http\Controllers\Api\V1\NitController;
use App\Http\Controllers\Api\V1\PuntoVentaController;
use App\Http\Controllers\Api\V1\SucursalController;
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
    });

    Route::post('facturas/{cuf}/anular', [FacturaController::class, 'anular'])
        ->middleware('siat.permiso:facturas:anular');

    // Emision: solo empresas en produccion y con idempotencia.
    Route::post('facturas', [FacturaController::class, 'store'])
        ->middleware(['siat.permiso:facturas:emitir', 'siat.produccion', 'siat.idempotencia']);
});
