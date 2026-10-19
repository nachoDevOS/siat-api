<?php

namespace App\Http\Middleware;

use App\Models\Empresa;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limita las peticiones POR EMPRESA para que un cliente con un bug (o abuso) no
 * afecte a los demas. Corre despues de AutenticarToken.
 *
 * Por defecto: 60 peticiones por minuto por token y 120 por empresa; se
 * ajustan por variable de entorno (SIAT_RATE_LIMIT_TOKEN, SIAT_RATE_LIMIT).
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

        // Dos limites: por token, para que una caja con un bucle no gaste el
        // cupo de las demas cajas de la misma empresa; y por empresa, para que
        // un cliente no afecte a los otros por mas tokens que tenga.
        //
        // Via config y no env(): con "config:cache" env() devuelve null y el
        // limite quedaria en 0, rechazando absolutamente todo.
        $limites = [
            'token:'.$empresa->currentAccessToken()?->getKey() => (int) config('siat.api.rate_limit_token'),
            "empresa:{$empresa->id}" => (int) config('siat.api.rate_limit'),
        ];

        foreach ($limites as $clave => $limite) {
            if (RateLimiter::tooManyAttempts($clave, $limite)) {
                $segundos = RateLimiter::availableIn($clave);
                $alcance = str_starts_with($clave, 'token:') ? 'este token' : 'la empresa';

                return response()->json([
                    'exito' => false,
                    'error' => 'DEMASIADAS_PETICIONES',
                    'mensaje' => "Supero el limite de {$limite} peticiones por minuto de {$alcance}. Reintente en {$segundos}s.",
                ], Response::HTTP_TOO_MANY_REQUESTS)->header('Retry-After', $segundos);
            }
        }

        foreach (array_keys($limites) as $clave) {
            RateLimiter::hit($clave, 60);
        }

        return $next($request);
    }
}
