<?php

use App\Jobs\AnularFacturaEnSiat;
use App\Jobs\EnviarFacturaAlSiat;
use App\Jobs\SincronizarCatalogosEmpresa;
use App\Jobs\ValidarPaqueteMasivo;
use App\Jobs\VerificarEstadoFactura;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\FacturaAnulada;
use App\Models\Paquete;
use App\Services\Trabajos\RegistroTrabajos;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tareas programadas (seccion 8.6 del documento maestro).
|--------------------------------------------------------------------------
*/

/*
 * Cada minuto: latido del scheduler. La pantalla "Trabajos" y el Dashboard lo
 * leen para avisar si el scheduler esta apagado (sin el, nada se renueva).
 */
Schedule::call(fn () => Cache::put(RegistroTrabajos::LATIDO_SCHEDULER, time(), now()->addDay()))
    ->everyMinute()->name('latido-scheduler');

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

/*
 * Cada 15 min: retoma las anulaciones que el SIN todavia no confirmo.
 *
 * El job agota sus cinco intentos en ~23 min (backoff 30/120/300/900) y deja la
 * anulacion PENDIENTE si el SIAT siguio caido; sin esto la factura quedaba
 * ANULADA de este lado y vigente ante el SIN para siempre. Se esperan 30 min
 * y el job es unico por factura, asi que no se pisa con uno en vuelo.
 */
Schedule::call(function () {
    FacturaAnulada::where('estado', FacturaAnulada::ESTADO_PENDIENTE)
        ->whereNull('codigo_recepcion')
        ->where('anulada_en', '<', now()->subMinutes(30))
        ->orderBy('id')
        ->limit(200)
        ->pluck('factura_id')
        ->each(fn (int $id) => AnularFacturaEnSiat::dispatch($id));
})->everyFifteenMinutes()->name('reintentar-anulaciones')->withoutOverlapping();

/*
 * Cada 15 min: retoma los paquetes de emision masiva que el SIN recibio pero
 * de los que todavia no se tiene el resultado. El job de validacion reintenta
 * ~38 min (backoff 60..1200); se esperan 45 para no pisarse con uno en vuelo.
 */
Schedule::call(function () {
    Paquete::where('tipo', Paquete::TIPO_MASIVA)
        ->where('estado', 'ENVIADO')
        ->where('enviado_en', '<', now()->subMinutes(45))
        ->orderBy('id')
        ->limit(100)
        ->pluck('id')
        ->each(fn (int $id) => ValidarPaqueteMasivo::dispatch($id));
})->everyFifteenMinutes()->name('validar-paquetes-masivos')->withoutOverlapping();

// Cada 10 min: cierra los eventos de contingencia cuyo SIAT ya volvio y manda
// el paquete. Sin esto una factura en CONTINGENCIA no llega nunca al SIN.
Schedule::command('siat:recuperar-contingencia')
    ->everyTenMinutes()
    ->name('recuperar-contingencia')
    ->withoutOverlapping();

// Cada hora: renueva los CUFD proximos a vencer (capa preventiva).
Schedule::command('siat:renovar-cufds')->hourly();

// Diario 02:00: revisa la vigencia del CUIS de cada punto de venta.
Schedule::command('siat:revisar-codigos')->dailyAt('02:00');

// Diario 07:00: alerta certificados que vencen en menos de 30 dias.
Schedule::command('siat:avisar-certificados')->dailyAt('07:00');

/*
 * Diario 03:30: catalogos del NIT de cada empresa (actividades, productos
 * homologados, leyendas). Un producto que el cliente homologa en la Oficina
 * Virtual no se puede facturar hasta que llega aca. Solo las empresas con token:
 * sin el no hay como hablar con el SIN; el job ademas se salta las que todavia
 * no tienen un punto de venta con CUIS vigente.
 */
Schedule::call(function () {
    Empresa::whereNotNull('token_delegado')
        ->pluck('id')
        ->each(fn (int $id) => SincronizarCatalogosEmpresa::dispatch($id));
})->dailyAt('03:30')->name('sincronizar-catalogos-empresas')->withoutOverlapping();

// Domingo 03:00: sincroniza catalogos globales.
Schedule::command('siat:sincronizar-globales')->weeklyOn(0, '03:00');

// Diario 04:00: poda la auditoria SOAP fuera del periodo de retencion.
Schedule::command('siat:purgar-logs')->dailyAt('04:00');
