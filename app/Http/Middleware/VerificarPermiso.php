<?php

namespace App\Http\Middleware;

use App\Models\Empresa;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que el token de la peticion tenga un permiso (ability de Sanctum).
 *
 * Uso en la ruta: ->middleware('siat.permiso:facturas:emitir'). Corre despues
 * de AutenticarToken. Asi un token de caja filtrado puede vender, pero no
 * anular facturas ni tocar codigos.
 */
class VerificarPermiso
{
    public function handle(Request $request, Closure $next, string $permiso): Response
    {
        /** @var Empresa|null $empresa */
        $empresa = $request->attributes->get('empresa');

        if ($empresa === null || ! $empresa->tokenCan($permiso)) {
            return response()->json([
                'exito' => false,
                'error' => 'PERMISO_INSUFICIENTE',
                'mensaje' => "El token no tiene el permiso '{$permiso}'.",
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
