<?php

namespace App\Providers;

use App\Services\InvoiceService;
use App\Services\Siat\CodigosService;
use App\Services\Siat\FacturacionService;
use App\Services\Siat\OperacionesService;
use App\Services\Siat\SincronizacionService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Servicios SOAP — singletons para reutilizar la instancia dentro del mismo request
        $this->app->singleton(CodigosService::class);
        $this->app->singleton(FacturacionService::class);
        $this->app->singleton(OperacionesService::class);
        $this->app->singleton(SincronizacionService::class);

        // Servicio principal de facturación — singleton, depende de los SOAP
        $this->app->singleton(InvoiceService::class, function ($app) {
            return new InvoiceService(
                $app->make(CodigosService::class),
                $app->make(FacturacionService::class),
                $app->make(OperacionesService::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
