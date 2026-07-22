<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActividadEconomica;
use App\Models\Catalogo;
use App\Models\Empresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Expone los catalogos que el sistema de ventas necesita para armar una venta:
 * unidades de medida, metodos de pago, actividades del NIT, etc.
 */
class CatalogoController extends Controller
{
    /**
     * GET /api/v1/catalogos/{tipo}
     *
     * Los tipos globales salen de la tabla catalogos; "actividades" es por
     * empresa y sale de actividades_economicas.
     */
    public function show(Request $request, string $tipo): JsonResponse
    {
        /** @var Empresa $empresa */
        $empresa = $request->attributes->get('empresa');

        // Las actividades economicas dependen del NIT, se tratan aparte.
        if ($tipo === 'actividades') {
            $datos = ActividadEconomica::where('empresa_id', $empresa->id)
                ->get(['codigo_actividad as codigo', 'descripcion']);

            return response()->json(['exito' => true, 'tipo' => $tipo, 'datos' => $datos]);
        }

        // El resto son catalogos globales, cacheados porque cambian poco.
        $datos = cache()->remember("siat.catalogos.{$tipo}", now()->addHour(), function () use ($tipo) {
            return Catalogo::deTipo($tipo)->get(['codigo_clasificador as codigo', 'descripcion']);
        });

        return response()->json(['exito' => true, 'tipo' => $tipo, 'datos' => $datos]);
    }
}
