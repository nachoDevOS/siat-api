<?php

use App\Jobs\EnviarFacturaAlSiat;
use App\Jobs\VerificarEstadoFactura;
use App\Models\Factura;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tareas programadas (seccion 8.6 del documento maestro).
|--------------------------------------------------------------------------
*/

// Cada 5 min: reintenta las facturas que quedaron pendientes de envio.
Schedule::call(function () {
    Factura::where('estado', Factura::ESTADO_PENDIENTE)
        ->orderBy('id')
        ->limit(200)
        ->pluck('id')
        ->each(fn (int $id) => EnviarFacturaAlSiat::dispatch($id));
})->everyFiveMinutes()->name('reintentar-pendientes')->withoutOverlapping();

// Cada 15 min: verifica el estado de las facturas enviadas sin confirmar.
Schedule::call(function () {
    Factura::whereIn('estado', [Factura::ESTADO_ENVIADA, Factura::ESTADO_RECIBIDA])
        ->orderBy('id')
        ->limit(200)
        ->pluck('id')
        ->each(fn (int $id) => VerificarEstadoFactura::dispatch($id));
})->everyFifteenMinutes()->name('verificar-enviadas')->withoutOverlapping();

// Cada hora: renueva los CUFD proximos a vencer (capa preventiva).
Schedule::command('siat:renovar-cufds')->hourly();

// Diario 02:00: revisa vigencia de CUIS y disponibilidad de CAFC.
Schedule::command('siat:revisar-codigos')->dailyAt('02:00');

// Diario 07:00: alerta certificados que vencen en menos de 30 dias.
Schedule::command('siat:avisar-certificados')->dailyAt('07:00');

// Domingo 03:00: sincroniza catalogos globales.
Schedule::command('siat:sincronizar-globales')->weeklyOn(0, '03:00');
