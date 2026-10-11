<?php

namespace App\Http\Middleware;

use App\Models\Empresa;
use App\Models\LogApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Deja constancia de cada llamada a la API en logs_api: token, empresa, ruta,
 * codigo HTTP, IP y duracion.
 *
 * Va primero en la cadena para registrar tambien los rechazos (401 de un
 * token invalido, 429): un token filtrado se nota justamente por esos.
 */
class RegistrarLlamadaApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $inicio = microtime(true);

        $respuesta = $next($request);

        try {
            /** @var Empresa|null $empresa */
            $empresa = $request->attributes->get('empresa');

            LogApi::create([
                'empresa_id' => $empresa?->id,
                'token_id' => $empresa?->currentAccessToken()?->getKey(),
                'metodo' => $request->method(),
                'ruta' => mb_substr('/'.$request->path(), 0, 255),
                'estado_http' => $respuesta->getStatusCode(),
                'ip' => $request->ip(),
                'duracion_ms' => (int) ((microtime(true) - $inicio) * 1000),
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Auditoria best-effort: nunca rompe la respuesta al cliente.
        }

        return $respuesta;
    }
}
