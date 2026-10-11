<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActividadEconomica;
use App\Models\Catalogo;
use App\Models\Empresa;
use App\Models\LeyendaFactura;
use App\Models\ProductoServicio;
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
     * Los tipos globales salen de la tabla catalogos; "actividades",
     * "productos" y "leyendas" son del NIT de la empresa.
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

        // Productos homologados del NIT: los unicos codigo_producto_sin que
        // la emision acepta una vez sincronizado el catalogo.
        if ($tipo === 'productos') {
            $datos = ProductoServicio::where('empresa_id', $empresa->id)
                ->orderBy('codigo_producto')
                ->get(['codigo_producto as codigo', 'codigo_actividad as actividad', 'descripcion']);

            return response()->json(['exito' => true, 'tipo' => $tipo, 'datos' => $datos]);
        }

        // Leyendas por actividad. La emision elige una sola si no viene en la
        // venta; se exponen para el que quiera fijarla.
        if ($tipo === 'leyendas') {
            $datos = LeyendaFactura::where('empresa_id', $empresa->id)
                ->orderBy('codigo_actividad')
                ->get(['codigo_actividad as actividad', 'descripcion_leyenda as descripcion']);

            return response()->json(['exito' => true, 'tipo' => $tipo, 'datos' => $datos]);
        }

        // El resto son catalogos globales, cacheados porque cambian poco.
        $datos = cache()->remember("siat.catalogos.{$tipo}", now()->addHour(), function () use ($tipo) {
            return Catalogo::deTipo($tipo)->get(['codigo_clasificador as codigo', 'descripcion']);
        });

        return response()->json(['exito' => true, 'tipo' => $tipo, 'datos' => $datos]);
    }
}
