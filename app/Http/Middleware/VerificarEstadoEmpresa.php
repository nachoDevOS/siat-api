<?php

namespace App\Http\Middleware;

use App\Models\Empresa;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Solo una empresa en estado PRODUCCION puede facturar por la API.
 * Corre despues de AutenticarApiKey, que ya dejo la empresa en el request.
 */
class VerificarEstadoEmpresa
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Empresa|null $empresa */
        $empresa = $request->attributes->get('empresa');

        if ($empresa === null || ! $empresa->estaEnProduccion()) {
            return response()->json([
                'exito' => false,
                'error' => 'EMPRESA_NO_HABILITADA',
                'mensaje' => 'La empresa aun no esta habilitada para facturar en produccion.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
