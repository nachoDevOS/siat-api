<?php

namespace App\Providers;

use App\Models\TokenApi;
use App\Services\Trabajos\RegistroTrabajos;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Pagination\Paginator;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // El panel muestra vigencias con diffForHumans ("vence en 22 horas").
        // Sin esto salen en ingles: "vence 22 hours from now". Se toca solo el
        // idioma de las fechas y no app.locale, que arrastraria las
        // traducciones de validacion, que no existen en este proyecto.
        Carbon::setLocale('es');

        // El panel no carga Tailwind: la paginacion usa una vista propia.
        Paginator::defaultView('pagination.panel');

        // Los tokens de la API llevan una columna propia (ips_permitidas).
        Sanctum::usePersonalAccessTokenModel(TokenApi::class);

        // Historial de la cola (pantalla "Trabajos" del panel). Un singleton
        // para que el latido del worker no escriba en cache en cada vuelta.
        $this->app->singleton(RegistroTrabajos::class);
        $registro = fn (): RegistroTrabajos => $this->app->make(RegistroTrabajos::class);
        Event::listen(JobQueued::class, fn (JobQueued $e) => $registro()->encolado($e));
        Event::listen(JobProcessing::class, fn (JobProcessing $e) => $registro()->procesando($e));
        Event::listen(JobProcessed::class, fn (JobProcessed $e) => $registro()->procesado($e));
        Event::listen(JobExceptionOccurred::class, fn (JobExceptionOccurred $e) => $registro()->excepcion($e));
        Event::listen(JobFailed::class, fn (JobFailed $e) => $registro()->fallido($e));
        Event::listen(JobRetryRequested::class, fn (JobRetryRequested $e) => $registro()->reintentoPedido($e));
        Event::listen(Looping::class, fn () => $registro()->latidoWorker());

        // Detras de un proxy, la IP real del sistema de ventas llega en
        // X-Forwarded-For; solo se le cree a los proxies configurados.
        $proxies = config('siat.proxies_confiables');

        if (filled($proxies)) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    }
}
