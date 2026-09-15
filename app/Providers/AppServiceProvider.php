<?php

namespace App\Providers;

use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;

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
    }
}
