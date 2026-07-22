<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Evita que un reintento del sistema de ventas genere dos facturas.
 *
 * La clave de idempotencia llega en X-Idempotency-Key y representa el id unico
 * de la venta en el sistema del cliente. Si el cuerpo no trae referencia_externa
 * propia, se usa esa clave como referencia.
 *
 * La deduplicacion real ocurre en el controlador: existe un indice unico
 * (empresa_id, referencia_externa), y ante una repeticion se devuelve la factura
 * ya emitida en vez de crear otra. Este middleware solo garantiza que la clave
 * quede disponible como referencia.
 */
class Idempotencia
{
    public function handle(Request $request, Closure $next): Response
    {
        $clave = $request->header('X-Idempotency-Key');

        // Si no vino referencia en el cuerpo, la clave de idempotencia hace de una.
        if (filled($clave) && blank($request->input('referencia_externa'))) {
            $request->merge(['referencia_externa' => $clave]);
        }

        return $next($request);
    }
}
