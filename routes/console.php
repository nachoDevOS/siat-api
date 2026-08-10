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

/*
 * Cada 5 min: reintenta las facturas que quedaron pendientes de envio.
 *
 * Solo se toman las que llevan mas de 10 minutos en PENDIENTE. El job de envio
 * agota sus tres intentos en ~7,5 min (backoff 30/120/300) y despues deriva a
 * contingencia, asi que una factura mas nueva que eso puede tener un job todavia
 * en vuelo: despacharla otra vez la mandaria dos veces al SIN.
 */
Schedule::call(function () {
    Factura::where('estado', Factura::ESTADO_PENDIENTE)
        ->where('created_at', '<', now()->subMinutes(10))
        ->orderBy('id')
        ->limit(200)
        ->pluck('id')
        ->each(fn (int $id) => EnviarFacturaAlSiat::dispatch($id));
})->everyFiveMinutes()->name('reintentar-pendientes')->withoutOverlapping();

/*
 * Cada 15 min: verifica el estado de las facturas enviadas sin confirmar.
 *
 * Mismo criterio: el job de verificacion reintenta hasta ~18 min (backoff
 * 60/120/300/600), asi que se espera 20 antes de volver a encolarla.
 */
Schedule::call(function () {
    Factura::whereIn('estado', [Factura::ESTADO_ENVIADA, Factura::ESTADO_RECIBIDA])
        ->where('enviada_en', '<', now()->subMinutes(20))
        ->orderBy('id')
        ->limit(200)
        ->pluck('id')
        ->each(fn (int $id) => VerificarEstadoFactura::dispatch($id));
})->everyFifteenMinutes()->name('verificar-enviadas')->withoutOverlapping();

// Cada 10 min: cierra los eventos de contingencia cuyo SIAT ya volvio y manda
// el paquete. Sin esto una factura en CONTINGENCIA no llega nunca al SIN.
Schedule::command('siat:recuperar-contingencia')
    ->everyTenMinutes()
    ->name('recuperar-contingencia')
    ->withoutOverlapping();

// Cada hora: renueva los CUFD proximos a vencer (capa preventiva).
Schedule::command('siat:renovar-cufds')->hourly();

// Diario 02:00: revisa vigencia de CUIS y disponibilidad de CAFC.
Schedule::command('siat:revisar-codigos')->dailyAt('02:00');

// Diario 07:00: alerta certificados que vencen en menos de 30 dias.
Schedule::command('siat:avisar-certificados')->dailyAt('07:00');

// Domingo 03:00: sincroniza catalogos globales.
Schedule::command('siat:sincronizar-globales')->weeklyOn(0, '03:00');

// Diario 04:00: poda la auditoria SOAP fuera del periodo de retencion.
Schedule::command('siat:purgar-logs')->dailyAt('04:00');
