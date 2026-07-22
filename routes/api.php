<?php

use App\Http\Controllers\Api\V1\CatalogoController;
use App\Http\Controllers\Api\V1\EstadoController;
use App\Http\Controllers\Api\V1\FacturaController;
use App\Http\Controllers\Api\V1\PuntoVentaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API REST v1 — la consume el sistema de ventas del cliente (seccion 10).
|--------------------------------------------------------------------------
|
| Toda ruta pasa por:
|   siat.apikey  -> identifica la empresa por su API key
|   siat.rate    -> limita peticiones por empresa
|
| Emitir facturas exige ademas empresa en PRODUCCION (siat.produccion) e
| idempotencia (siat.idempotencia).
*/

Route::prefix('v1')->middleware(['siat.apikey', 'siat.rate'])->group(function () {

    // Salud de la integracion.
    Route::get('estado', EstadoController::class);

    // Catalogos para armar la venta.
    Route::get('catalogos/{tipo}', [CatalogoController::class, 'show']);

    // Puntos de venta.
    Route::get('puntos-venta', [PuntoVentaController::class, 'index']);
    Route::post('puntos-venta', [PuntoVentaController::class, 'store']);

    // Consulta y descarga de facturas (no exige estar en produccion).
    Route::get('facturas', [FacturaController::class, 'index']);
    Route::get('facturas/{cuf}', [FacturaController::class, 'show']);
    Route::get('facturas/{cuf}/pdf', [FacturaController::class, 'pdf']);
    Route::get('facturas/{cuf}/xml', [FacturaController::class, 'xml']);
    Route::post('facturas/{cuf}/anular', [FacturaController::class, 'anular']);

    // Emision: solo empresas en produccion y con idempotencia.
    Route::post('facturas', [FacturaController::class, 'store'])
        ->middleware(['siat.produccion', 'siat.idempotencia']);
});
