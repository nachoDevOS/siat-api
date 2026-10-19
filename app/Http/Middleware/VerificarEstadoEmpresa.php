<?php

namespace App\Http\Middleware;

use App\Models\Empresa;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Solo una empresa habilitada puede facturar por la API: en produccion, la que
 * esta en estado PRODUCCION; en el piloto (sandbox), tambien la que corre o ya
 * aprobo las pruebas (Empresa::puedeEmitirPorApi).
 * Corre despues de AutenticarToken, que ya dejo la empresa en el request.
 */
class VerificarEstadoEmpresa
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Empresa|null $empresa */
        $empresa = $request->attributes->get('empresa');

        if ($empresa === null || ! $empresa->puedeEmitirPorApi()) {
            return response()->json([
                'exito' => false,
                'error' => 'EMPRESA_NO_HABILITADA',
                'mensaje' => $empresa?->esSandbox()
                    ? 'La empresa de pruebas todavia no esta lista para emitir: el proveedor tiene que pasarla a EN_PRUEBAS.'
                    : 'La empresa aun no esta habilitada para facturar en produccion.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
