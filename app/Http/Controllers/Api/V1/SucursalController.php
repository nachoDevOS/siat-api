<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/sucursales — sucursales de la empresa con sus puntos de venta
 * activos, para que el sistema de ventas sepa con que codigos facturar.
 */
class SucursalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Empresa $empresa */
        $empresa = $request->attributes->get('empresa');

        $sucursales = $empresa->sucursales()
            ->with(['puntosVenta' => fn ($q) => $q->where('activo', true)->orderBy('codigo_punto_venta')])
            ->orderBy('codigo_sucursal')
            ->get();

        return response()->json([
            'exito' => true,
            'sucursales' => $sucursales->map(fn ($sucursal) => [
                'sucursal' => $sucursal->codigo_sucursal,
                'nombre' => $sucursal->nombre,
                'municipio' => $sucursal->municipio,
                'direccion' => $sucursal->direccion,
                'telefono' => $sucursal->telefono,
                'puntos_venta' => $sucursal->puntosVenta->map(fn ($pv) => [
                    'punto_venta' => $pv->codigo_punto_venta,
                    'nombre' => $pv->nombre,
                    'registrado_en_siat' => $pv->existeEnElSiat(),
                ])->values(),
            ])->values(),
        ]);
    }
}
