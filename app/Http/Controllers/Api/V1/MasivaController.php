<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LoteMasivo;
use App\Models\Paquete;
use App\Models\PuntoVenta;
use App\Services\Factura\GestorMasiva;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emision masiva (modalidad del SIN para alto volumen): se abre un lote en un
 * punto de venta, se emite con POST /facturas como siempre y al cerrar el lote
 * las facturas viajan en paquetes de hasta 500.
 */
class MasivaController extends Controller
{
    /**
     * POST /api/v1/masiva/{sucursal}/{puntoVenta}/abrir — idempotente.
     */
    public function abrir(Request $request, int $sucursal, int $puntoVenta, GestorMasiva $gestor): JsonResponse
    {
        $lote = $gestor->abrir($this->puntoVenta($request, $sucursal, $puntoVenta));

        return response()->json(['exito' => true, 'lote' => $this->forma($lote)], Response::HTTP_CREATED);
    }

    /**
     * GET /api/v1/masiva/{sucursal}/{puntoVenta} — el lote abierto o el ultimo.
     */
    public function show(Request $request, int $sucursal, int $puntoVenta): JsonResponse
    {
        $lote = LoteMasivo::where('punto_venta_id', $this->puntoVenta($request, $sucursal, $puntoVenta)->id)
            ->latest('id')
            ->first();

        return response()->json(['exito' => true, 'lote' => $lote === null ? null : $this->forma($lote)]);
    }

    /**
     * POST /api/v1/masiva/{sucursal}/{puntoVenta}/cerrar — arma y encola los
     * paquetes. El resultado de cada factura llega por webhook.
     */
    public function cerrar(Request $request, int $sucursal, int $puntoVenta, GestorMasiva $gestor): JsonResponse
    {
        try {
            $lote = $gestor->cerrar($this->puntoVenta($request, $sucursal, $puntoVenta));
        } catch (RuntimeException $e) {
            return response()->json(['exito' => false, 'error' => 'SIN_LOTE_ABIERTO', 'mensaje' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'exito' => true,
            'mensaje' => 'Lote cerrado: los paquetes se envian al SIN en segundo plano.',
            'lote' => $this->forma($lote),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function forma(LoteMasivo $lote): array
    {
        return [
            'id' => $lote->id,
            'estado' => $lote->estado,
            'abierto_en' => $lote->abierto_en?->toIso8601String(),
            'cerrado_en' => $lote->cerrado_en?->toIso8601String(),
            'facturas' => $lote->facturas()->count(),
            'facturas_por_estado' => $lote->facturas()->selectRaw('estado, COUNT(*) as cantidad')->groupBy('estado')
                ->pluck('cantidad', 'estado')->map(fn ($n) => (int) $n),
            'paquetes' => $lote->paquetes()->orderBy('id')->get()->map(fn (Paquete $p) => [
                'id' => $p->id,
                'estado' => $p->estado,
                'cantidad_facturas' => $p->cantidad_facturas,
                'codigo_recepcion' => $p->codigo_recepcion,
                'motivo_observacion' => $p->motivo_observacion,
            ])->all(),
        ];
    }

    private function puntoVenta(Request $request, int $sucursal, int $puntoVenta): PuntoVenta
    {
        return PuntoVenta::deEmpresaPorCodigos($request->attributes->get('empresa'), $sucursal, $puntoVenta);
    }
}
