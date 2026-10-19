<?php

use App\Http\Middleware\AutenticarToken;
use App\Http\Middleware\Idempotencia;
use App\Http\Middleware\LimitarPeticiones;
use App\Http\Middleware\RegistrarLlamadaApi;
use App\Http\Middleware\VerificarEstadoEmpresa;
use App\Http\Middleware\VerificarPermiso;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // El sitio publico va en su propio archivo, con el grupo 'web' (sesion y CSRF).
        then: function () {
            Route::middleware('web')->group(base_path('routes/sitio.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Alias de los middleware propios de la API para usarlos en las rutas.
        $middleware->alias([
            'siat.registro' => RegistrarLlamadaApi::class,
            'siat.token' => AutenticarToken::class,
            'siat.permiso' => VerificarPermiso::class,
            'siat.produccion' => VerificarEstadoEmpresa::class,
            'siat.idempotencia' => Idempotencia::class,
            'siat.rate' => LimitarPeticiones::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // La API y el formulario del sitio publico (lo envia React por fetch)
        // reciben los errores en JSON, tambien los de validacion.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('contacto'),
        );

        // Una factura publica con firma invalida responde 404, no 403: asi no
        // se distingue "existe pero el enlace es falso" de "no existe".
        $exceptions->render(function (InvalidSignatureException $e, Request $request) {
            if ($request->routeIs('factura.publica*')) {
                abort(404);
            }
        });
    })->create();
