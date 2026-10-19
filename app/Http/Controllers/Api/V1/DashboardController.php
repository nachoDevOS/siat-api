<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Factura;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/dashboard — resumen de lo facturado por la empresa, para el
 * tablero del sistema de ventas sin que tenga que recorrer el listado.
 *
 * Los montos cuentan solo facturas vigentes (no anuladas). Las notas de
 * credito-debito van aparte: restan lo devuelto, no son ventas.
 */
class DashboardController extends Controller
{
    /** Meses que cubre la tendencia, contando el actual. */
    private const MESES_TENDENCIA = 6;

    public function __invoke(Request $request): JsonResponse
    {
        /** @var Empresa $empresa */
        $empresa = $request->attributes->get('empresa');
        $sectorNota = config('siat.codigos.nota_credito_debito.documento_sector');

        $facturas = fn (): Builder => Factura::where('empresa_id', $empresa->id)
            ->where('codigo_documento_sector', '!=', $sectorNota);
        $vigentes = fn (): Builder => $facturas()->where('estado', '!=', Factura::ESTADO_ANULADA);
        $notasVigentes = fn (): Builder => Factura::where('empresa_id', $empresa->id)
            ->where('codigo_documento_sector', $sectorNota)
            ->where('estado', '!=', Factura::ESTADO_ANULADA);

        $inicioMes = now()->startOfMonth();
        $inicioAnio = now()->startOfYear();

        $tendencia = [];
        for ($i = self::MESES_TENDENCIA - 1; $i >= 0; $i--) {
            $desde = now()->startOfMonth()->subMonthsNoOverflow($i);
            $hasta = $desde->copy()->endOfMonth();
            $delMes = fn (): Builder => $vigentes()->whereBetween('fecha_emision', [$desde, $hasta]);

            $tendencia[] = [
                'mes' => $desde->format('Y-m'),
                'cantidad' => $delMes()->count(),
                'monto' => round((float) $delMes()->sum('monto_total'), 2),
            ];
        }

        return response()->json([
            'exito' => true,
            // En el sandbox lo emitido no tiene validez fiscal.
            'ambiente' => $empresa->esSandbox() ? 'PILOTO' : 'PRODUCCION',
            'facturas' => [
                'total' => $facturas()->count(),
                'mes' => [
                    'cantidad' => $vigentes()->where('fecha_emision', '>=', $inicioMes)->count(),
                    'monto' => round((float) $vigentes()->where('fecha_emision', '>=', $inicioMes)->sum('monto_total'), 2),
                ],
                'anio' => [
                    'cantidad' => $vigentes()->where('fecha_emision', '>=', $inicioAnio)->count(),
                    'monto' => round((float) $vigentes()->where('fecha_emision', '>=', $inicioAnio)->sum('monto_total'), 2),
                ],
                'por_estado' => $facturas()->selectRaw('estado, COUNT(*) as cantidad')->groupBy('estado')
                    ->pluck('cantidad', 'estado')->map(fn ($n) => (int) $n),
                'tendencia' => $tendencia,
            ],
            'notas_credito_debito' => [
                'mes' => [
                    'cantidad' => $notasVigentes()->where('fecha_emision', '>=', $inicioMes)->count(),
                    'monto_devuelto' => round((float) $notasVigentes()->where('fecha_emision', '>=', $inicioMes)->sum('monto_total'), 2),
                ],
            ],
        ]);
    }
}
