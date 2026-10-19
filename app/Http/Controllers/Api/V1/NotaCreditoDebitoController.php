<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\CufdVencidoException;
use App\Exceptions\FacturaInvalidaException;
use App\Http\Controllers\Controller;
use App\Http\Requests\EmitirNotaCreditoDebitoRequest;
use App\Http\Resources\FacturaResource;
use App\Models\Factura;
use App\Services\Factura\EmisorNotaCreditoDebito;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notas de credito-debito de una factura (devoluciones).
 *
 * Una nota emitida es un documento mas, con su propio CUF: se consulta,
 * descarga y anula con los mismos endpoints de /facturas/{cuf}.
 */
class NotaCreditoDebitoController extends Controller
{
    /**
     * POST /api/v1/facturas/{cuf}/notas — emite una nota sobre la factura.
     */
    public function store(EmitirNotaCreditoDebitoRequest $request, string $cuf, EmisorNotaCreditoDebito $emisor): JsonResponse
    {
        $origen = $this->buscar($request, $cuf);

        try {
            $nota = $emisor->emitir($origen, $request->devolucion());
        } catch (FacturaInvalidaException $e) {
            return response()->json([
                'exito' => false,
                'error' => 'NOTA_INVALIDA',
                'mensaje' => 'La devolucion no se puede emitir.',
                'detalles' => $e->errores,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (CufdVencidoException $e) {
            return response()->json([
                'exito' => false,
                'error' => 'SIAT_NO_DISPONIBLE',
                'mensaje' => $e->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json([
            'exito' => true,
            'nota' => new FacturaResource($nota->load('puntoVenta.sucursal', 'facturaOrigen', 'anulacion')),
        ], Response::HTTP_CREATED);
    }

    /**
     * GET /api/v1/facturas/{cuf}/notas — notas emitidas sobre la factura.
     */
    public function index(Request $request, string $cuf): JsonResponse
    {
        $origen = $this->buscar($request, $cuf);

        $notas = $origen->notas()
            ->with(['puntoVenta.sucursal', 'facturaOrigen', 'anulacion'])
            ->latest('fecha_emision')
            ->latest('id')
            ->get();

        return response()->json([
            'exito' => true,
            'notas' => FacturaResource::collection($notas),
        ]);
    }

    /**
     * La factura de la empresa autenticada, o 404. Solo facturas: sobre una
     * nota no se emiten notas, y eso lo responde el emisor con su motivo.
     */
    private function buscar(Request $request, string $cuf): Factura
    {
        return Factura::where('empresa_id', $request->attributes->get('empresa')->id)
            ->where('cuf', $cuf)
            ->firstOrFail();
    }
}
