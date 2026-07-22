<?php

namespace App\Http\Middleware;

use App\Models\Empresa;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limita las peticiones POR EMPRESA para que un cliente con un bug (o abuso) no
 * afecte a los demas. Corre despues de AutenticarApiKey.
 *
 * El limite por defecto es 120 peticiones por minuto por empresa; se puede
 * ajustar por variable de entorno.
 */
class LimitarPeticiones
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Empresa|null $empresa */
        $empresa = $request->attributes->get('empresa');

        // Sin empresa identificada no hay a quien limitar; que decida el auth.
        if ($empresa === null) {
            return $next($request);
        }

        $limite = (int) env('SIAT_RATE_LIMIT', 120);
        $clave = "empresa:{$empresa->id}";

        if (RateLimiter::tooManyAttempts($clave, $limite)) {
            $segundos = RateLimiter::availableIn($clave);

            return response()->json([
                'exito' => false,
                'error' => 'DEMASIADAS_PETICIONES',
                'mensaje' => "Supero el limite de {$limite} peticiones por minuto. Reintente en {$segundos}s.",
            ], Response::HTTP_TOO_MANY_REQUESTS)->header('Retry-After', $segundos);
        }

        RateLimiter::hit($clave, 60);

        return $next($request);
    }
}
