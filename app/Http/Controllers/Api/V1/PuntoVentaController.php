<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PuntoVentaResource;
use App\Models\Empresa;
use App\Models\PuntoVenta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints de puntos de venta para el sistema de ventas del cliente.
 */
class PuntoVentaController extends Controller
{
    /**
     * GET /api/v1/puntos-venta — puntos de venta habilitados de la empresa.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Empresa $empresa */
        $empresa = $request->attributes->get('empresa');

        $puntos = PuntoVenta::query()
            ->whereHas('sucursal', fn ($q) => $q->where('empresa_id', $empresa->id))
            ->where('activo', true)
            ->with('sucursal')
            ->get();

        return response()->json([
            'exito' => true,
            'puntos_venta' => PuntoVentaResource::collection($puntos),
        ]);
    }

    /**
     * POST /api/v1/puntos-venta — alta de un punto de venta.
     *
     * El registro en el SIAT (registroPuntoVenta + CUIS + primer CUFD) lo hace
     * ServicioOperaciones contra el WSDL; aca solo se crea el registro local que
     * despues el flujo de alta sincroniza. Ver seccion 8.5.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var Empresa $empresa */
        $empresa = $request->attributes->get('empresa');

        $datos = $request->validate([
            'sucursal' => ['required', 'integer', 'min:0'],
            'nombre' => ['required', 'string', 'max:255'],
            'tipo_punto_venta' => ['required', 'integer'],
        ]);

        $sucursal = $empresa->sucursales()
            ->where('codigo_sucursal', $datos['sucursal'])
            ->firstOrFail();

        // El siguiente codigo local se asigna correlativo dentro de la sucursal.
        $siguienteCodigo = (int) $sucursal->puntosVenta()->max('codigo_punto_venta') + 1;

        $punto = $sucursal->puntosVenta()->create([
            'codigo_punto_venta' => $siguienteCodigo,
            'nombre' => $datos['nombre'],
            'tipo_punto_venta' => $datos['tipo_punto_venta'],
            'siguiente_factura' => 1,
            'activo' => true,
        ]);

        return response()->json([
            'exito' => true,
            'punto_venta' => new PuntoVentaResource($punto->load('sucursal')),
        ], 201);
    }
}
