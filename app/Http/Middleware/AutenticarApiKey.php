<?php

namespace App\Http\Middleware;

use App\Models\Empresa;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifica la EMPRESA dueña de la peticion por su API key.
 *
 * La key llega en la cabecera X-Api-Key. Guardamos solo su hash SHA-256, nunca
 * la clave en claro. Se cachea la empresa 5 minutos para no golpear la base en
 * cada factura (ver seccion 11.1 del documento maestro).
 *
 * Se usa un middleware propio y no Sanctum porque la key identifica a una
 * empresa en comunicacion servidor-a-servidor, no a un usuario.
 */
class AutenticarApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Api-Key');

        if (blank($key)) {
            return $this->rechazar();
        }

        $hash = hash('sha256', $key);

        // La empresa se cachea por su hash. El propio modelo invalida esta
        // entrada al guardarse, asi que una key revocada deja de servir al toque
        // y no cuando venza el TTL.
        $minutos = (int) config('siat.api.cache_apikey_minutos');

        $empresa = Cache::remember(
            Empresa::claveCacheApiKey($hash),
            now()->addMinutes($minutos),
            fn () => Empresa::where('api_key_hash', $hash)->first(),
        );

        if ($empresa === null) {
            return $this->rechazar();
        }

        // La empresa queda disponible para el resto de la cadena y el controlador.
        $request->attributes->set('empresa', $empresa);

        return $next($request);
    }

    private function rechazar(): Response
    {
        return response()->json([
            'exito' => false,
            'error' => 'API_KEY_INVALIDA',
            'mensaje' => 'La clave de API no existe o no fue enviada.',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
