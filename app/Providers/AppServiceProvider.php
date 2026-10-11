<?php

namespace App\Providers;

use App\Models\TokenApi;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
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

        // Detras de un proxy, la IP real del sistema de ventas llega en
        // X-Forwarded-For; solo se le cree a los proxies configurados.
        $proxies = config('siat.proxies_confiables');

        if (filled($proxies)) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    }
}
