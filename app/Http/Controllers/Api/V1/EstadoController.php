<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\PuntoVenta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/estado — salud de la integracion para el cliente: si su empresa
 * esta habilitada y si sus puntos de venta tienen CUFD vigente para facturar.
 */
class EstadoController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Empresa $empresa */
        $empresa = $request->attributes->get('empresa');

        $puntos = PuntoVenta::query()
            ->whereHas('sucursal', fn ($q) => $q->where('empresa_id', $empresa->id))
            ->where('activo', true)
            ->get();

        // Se puede facturar si al menos un punto de venta tiene CUFD vigente.
        $conCufd = $puntos->filter(fn (PuntoVenta $pv) => $pv->cufdVigente() !== null);

        return response()->json([
            'exito' => true,
            'empresa' => [
                'estado' => $empresa->estado,
                'en_produccion' => $empresa->estaEnProduccion(),
            ],
            'puntos_venta_activos' => $puntos->count(),
            'puntos_venta_con_cufd' => $conCufd->count(),
            'puede_facturar' => $empresa->estaEnProduccion() && $conCufd->isNotEmpty(),
        ]);
    }
}
