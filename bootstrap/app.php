<?php

use App\Http\Middleware\AutenticarApiKey;
use App\Http\Middleware\Idempotencia;
use App\Http\Middleware\LimitarPeticiones;
use App\Http\Middleware\VerificarEstadoEmpresa;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Alias de los middleware propios de la API para usarlos en las rutas.
        $middleware->alias([
            'siat.apikey' => AutenticarApiKey::class,
            'siat.produccion' => VerificarEstadoEmpresa::class,
            'siat.idempotencia' => Idempotencia::class,
            'siat.rate' => LimitarPeticiones::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
