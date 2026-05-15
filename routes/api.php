<?php

use App\Http\Controllers\Api\CatalogosController;
use App\Http\Controllers\Api\CodigosController;
use App\Http\Controllers\Api\FacturacionController;
use App\Http\Controllers\Api\PaquetesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API SIAT — v1
|--------------------------------------------------------------------------
|
| Todas las rutas requieren autenticación via Sanctum (Bearer token).
|
| Prefijo: /api/v1
|
| Grupos:
|   /codigos    — CUIS, CUFD, ping, NIT
|   /facturas   — emisión, anulación, reversión, estado
|   /paquetes   — contingencia: reconciliar, enviar, verificar estado
|   /catalogos  — catálogos SIAT (consulta y sincronización)
|
*/

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {

    // ─────────────────────────────────────────────────────────────────────────
    // CÓDIGOS (CUIS / CUFD / NIT)
    // ─────────────────────────────────────────────────────────────────────────

    Route::prefix('codigos')->group(function () {
        // GET  /api/v1/codigos/ping
        Route::get('ping', [CodigosController::class, 'ping'])->name('codigos.ping');

        // POST /api/v1/codigos/sync
        Route::post('sync', [CodigosController::class, 'sync'])->name('codigos.sync');

        // GET  /api/v1/codigos/context?codigo_sucursal=0&codigo_punto_venta=0
        Route::get('context', [CodigosController::class, 'context'])->name('codigos.context');

        // POST /api/v1/codigos/verificar-nit
        Route::post('verificar-nit', [CodigosController::class, 'verificarNit'])->name('codigos.verificar-nit');
    });

    // ─────────────────────────────────────────────────────────────────────────
    // FACTURAS
    // ─────────────────────────────────────────────────────────────────────────

    Route::prefix('facturas')->group(function () {
        // POST /api/v1/facturas
        Route::post('/', [FacturacionController::class, 'store'])->name('facturas.store');

        // GET  /api/v1/facturas/{id}
        Route::get('{id}', [FacturacionController::class, 'show'])->name('facturas.show');

        // POST /api/v1/facturas/{id}/anular
        Route::post('{id}/anular', [FacturacionController::class, 'anular'])->name('facturas.anular');

        // POST /api/v1/facturas/{id}/revertir
        Route::post('{id}/revertir', [FacturacionController::class, 'revertir'])->name('facturas.revertir');

        // GET  /api/v1/facturas/{id}/estado
        Route::get('{id}/estado', [FacturacionController::class, 'estado'])->name('facturas.estado');
    });

    // ─────────────────────────────────────────────────────────────────────────
    // PAQUETES DE CONTINGENCIA
    // ─────────────────────────────────────────────────────────────────────────

    Route::prefix('paquetes')->group(function () {
        // GET  /api/v1/paquetes?codigo_sucursal=0&codigo_punto_venta=0
        Route::get('/', [PaquetesController::class, 'index'])->name('paquetes.index');

        // GET  /api/v1/paquetes/pendientes?codigo_sucursal=0&codigo_punto_venta=0
        Route::get('pendientes', [PaquetesController::class, 'pendientes'])->name('paquetes.pendientes');

        // POST /api/v1/paquetes/reconciliar
        Route::post('reconciliar', [PaquetesController::class, 'reconciliar'])->name('paquetes.reconciliar');

        // POST /api/v1/paquetes/enviar
        Route::post('enviar', [PaquetesController::class, 'enviar'])->name('paquetes.enviar');

        // GET  /api/v1/paquetes/{id}/estado
        Route::get('{id}/estado', [PaquetesController::class, 'estado'])->name('paquetes.estado');
    });

    // ─────────────────────────────────────────────────────────────────────────
    // CATÁLOGOS SIAT
    // ─────────────────────────────────────────────────────────────────────────

    Route::prefix('catalogos')->group(function () {
        // Consultas locales (sin llamar al SIN)
        Route::get('actividades',           [CatalogosController::class, 'actividades'])->name('catalogos.actividades');
        Route::get('leyendas',              [CatalogosController::class, 'leyendas'])->name('catalogos.leyendas');
        Route::get('motivos-anulacion',     [CatalogosController::class, 'motivosAnulacion'])->name('catalogos.motivos-anulacion');
        Route::get('eventos-significativos', [CatalogosController::class, 'eventosSignificativos'])->name('catalogos.eventos-significativos');

        // Sincronización desde SIAT (requieren CUIS vigente)
        Route::prefix('sync')->group(function () {
            Route::post('todos',                  [CatalogosController::class, 'syncTodos'])->name('catalogos.sync.todos');
            Route::post('actividades',            [CatalogosController::class, 'syncActividades'])->name('catalogos.sync.actividades');
            Route::post('leyendas',               [CatalogosController::class, 'syncLeyendas'])->name('catalogos.sync.leyendas');
            Route::post('motivos-anulacion',      [CatalogosController::class, 'syncMotivosAnulacion'])->name('catalogos.sync.motivos-anulacion');
            Route::post('eventos-significativos', [CatalogosController::class, 'syncEventosSignificativos'])->name('catalogos.sync.eventos-significativos');
            Route::post('tipos-documento',        [CatalogosController::class, 'syncTiposDocumento'])->name('catalogos.sync.tipos-documento');
            Route::post('metodos-pago',           [CatalogosController::class, 'syncMetodosPago'])->name('catalogos.sync.metodos-pago');
            Route::post('unidades-medida',        [CatalogosController::class, 'syncUnidadesMedida'])->name('catalogos.sync.unidades-medida');
            Route::post('productos-servicios',    [CatalogosController::class, 'syncProductosServicios'])->name('catalogos.sync.productos-servicios');
            Route::post('tipos-punto-venta',      [CatalogosController::class, 'syncTiposPuntoVenta'])->name('catalogos.sync.tipos-punto-venta');
            Route::post('tipos-moneda',           [CatalogosController::class, 'syncTiposMoneda'])->name('catalogos.sync.tipos-moneda');
        });
    });
});
