<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\PuntoVenta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/estado — salud de la integracion para el cliente: si su empresa
 * esta habilitada y si tiene puntos de venta con los que facturar.
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

        $conCufd = $puntos->filter(fn (PuntoVenta $pv) => $pv->cufdVigente() !== null);

        // Se puede facturar con un punto de venta que el SIN conoce. No hace
        // falta que tenga CUFD ahora: la emision lo pide en el momento.
        $registrados = $puntos->filter(fn (PuntoVenta $pv) => $pv->existeEnElSiat());

        return response()->json([
            'exito' => true,
            'empresa' => [
                'estado' => $empresa->estado,
                'en_produccion' => $empresa->estaEnProduccion(),
            ],
            'puntos_venta_activos' => $puntos->count(),
            'puntos_venta_registrados_en_siat' => $registrados->count(),
            'puntos_venta_con_cufd' => $conCufd->count(),
            'puede_facturar' => $empresa->estaEnProduccion() && $registrados->isNotEmpty(),
        ]);
    }
}
