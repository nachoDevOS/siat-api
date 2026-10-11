<?php

namespace App\Http\Middleware;

use App\Models\Empresa;
use App\Models\TokenApi;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifica la EMPRESA duena de la peticion por su token de Sanctum.
 *
 * El token llega como "Authorization: Bearer siat_...". El guard de Sanctum
 * ya hace lo delicado: busca el token por su hash, rechaza los vencidos y
 * marca last_used_at. Aca se agrega lo propio de este sistema:
 *
 *   - el dueno del token tiene que ser una Empresa (los tokens son por
 *     contribuyente, no por usuario del panel);
 *   - si el token tiene IPs permitidas, la peticion debe venir de una de ellas.
 *
 * No se usa el middleware 'auth:sanctum' directo porque responde con el JSON
 * generico de Laravel; los sistemas de venta esperan el formato de errores de
 * esta API ({exito, error, mensaje}).
 */
class AutenticarToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $empresa = Auth::guard('sanctum')->user();

        if (! $empresa instanceof Empresa) {
            return $this->rechazar(
                'TOKEN_INVALIDO',
                'El token no existe, vencio, fue revocado o no fue enviado (Authorization: Bearer).',
                Response::HTTP_UNAUTHORIZED,
            );
        }

        /** @var TokenApi $token */
        $token = $empresa->currentAccessToken();

        if (! $token->aceptaIp($request->ip())) {
            return $this->rechazar(
                'IP_NO_PERMITIDA',
                "Este token no admite peticiones desde la IP {$request->ip()}.",
                Response::HTTP_FORBIDDEN,
            );
        }

        // La empresa queda disponible para el resto de la cadena y el controlador.
        $request->attributes->set('empresa', $empresa);

        return $next($request);
    }

    private function rechazar(string $error, string $mensaje, int $codigo): Response
    {
        return response()->json([
            'exito' => false,
            'error' => $error,
            'mensaje' => $mensaje,
        ], $codigo);
    }
}
