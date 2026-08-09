<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\CufdVencidoException;
use App\Exceptions\FacturaInvalidaException;
use App\Exceptions\FacturaObservadaException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnularFacturaRequest;
use App\Http\Requests\EmitirFacturaRequest;
use App\Http\Resources\FacturaResource;
use App\Jobs\AnularFacturaEnSiat;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\FacturaAnulada;
use App\Services\Factura\EmisorFactura;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoints de facturas para el sistema de ventas del cliente (seccion 10).
 * Toda consulta filtra por la empresa autenticada para aislar los datos.
 */
class FacturaController extends Controller
{
    /**
     * Estados en los que el SIN ya conoce la factura y por lo tanto acepta su
     * anulacion.
     *
     * @var list<string>
     */
    private const ESTADOS_ANULABLES = [
        Factura::ESTADO_ENVIADA,
        Factura::ESTADO_RECIBIDA,
        Factura::ESTADO_VALIDADA,
    ];

    /**
     * POST /api/v1/facturas — emite una factura y responde con el CUF.
     */
    public function store(EmitirFacturaRequest $request, EmisorFactura $emisor): JsonResponse
    {
        $empresa = $this->empresa($request);

        try {
            $factura = $emisor->emitir($empresa, $request->venta());
        } catch (FacturaInvalidaException $e) {
            // Reglas de negocio que el FormRequest no cubre.
            return response()->json([
                'exito' => false,
                'error' => 'FACTURA_INVALIDA',
                'mensaje' => 'La venta no cumple las validaciones previas al envio.',
                'detalles' => $e->errores,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (CufdVencidoException $e) {
            // Sin CUFD vigente y sin contingencia configurada: el SIAT no esta disponible.
            return response()->json([
                'exito' => false,
                'error' => 'SIAT_NO_DISPONIBLE',
                'mensaje' => $e->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (FacturaObservadaException $e) {
            return response()->json([
                'exito' => false,
                'error' => 'FACTURA_OBSERVADA',
                'mensaje' => 'El SIAT rechazo la factura',
                'detalles' => $e->observaciones,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Una factura en contingencia ya es valida; se responde 202.
        $codigo = $factura->esContingencia()
            ? Response::HTTP_ACCEPTED
            : Response::HTTP_CREATED;

        return response()->json([
            'exito' => true,
            'factura' => new FacturaResource($factura),
        ], $codigo);
    }

    /**
     * GET /api/v1/facturas/{cuf} — estado actual de una factura.
     */
    public function show(Request $request, string $cuf): JsonResponse
    {
        $factura = $this->buscar($request, $cuf);

        return response()->json([
            'exito' => true,
            'factura' => new FacturaResource($factura),
        ]);
    }

    /**
     * GET /api/v1/facturas — listado paginado con filtros de fecha.
     */
    public function index(Request $request): JsonResponse
    {
        $empresa = $this->empresa($request);

        $facturas = Factura::where('empresa_id', $empresa->id)
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('fecha_emision', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('fecha_emision', '<=', $request->date('hasta')))
            ->with('puntoVenta.sucursal')
            ->latest('fecha_emision')
            ->paginate(50);

        return response()->json([
            'exito' => true,
            'facturas' => FacturaResource::collection($facturas),
            'paginacion' => [
                'total' => $facturas->total(),
                'pagina' => $facturas->currentPage(),
                'por_pagina' => $facturas->perPage(),
            ],
        ]);
    }

    /**
     * POST /api/v1/facturas/{cuf}/anular — anula una factura validada.
     */
    public function anular(AnularFacturaRequest $request, string $cuf): JsonResponse
    {
        $factura = $this->buscar($request, $cuf);

        // Repetir la anulacion no vuelve a registrarla ni a molestar al SIAT.
        if ($factura->estado === Factura::ESTADO_ANULADA) {
            return response()->json([
                'exito' => true,
                'mensaje' => 'La factura ya estaba anulada.',
                'cuf' => $factura->cuf,
            ]);
        }

        // Solo se puede anular lo que el SIN ya conoce; una factura que todavia
        // no viajo se corrige antes de enviarla, no se anula.
        if (! in_array($factura->estado, self::ESTADOS_ANULABLES, true)) {
            return response()->json([
                'exito' => false,
                'error' => 'FACTURA_NO_ANULABLE',
                'mensaje' => "Una factura en estado {$factura->estado} no se puede anular.",
            ], Response::HTTP_CONFLICT);
        }

        // Se registra la anulacion y se marca la factura de inmediato; la
        // transmision al SIAT la hace AnularFacturaEnSiat en segundo plano.
        FacturaAnulada::updateOrCreate(
            ['factura_id' => $factura->id],
            ['motivo' => $request->integer('motivo'), 'anulada_en' => now()],
        );

        $factura->update(['estado' => Factura::ESTADO_ANULADA]);

        AnularFacturaEnSiat::dispatch($factura->id);

        return response()->json([
            'exito' => true,
            'mensaje' => 'Anulacion registrada. Pendiente de confirmacion del SIAT.',
            'cuf' => $factura->cuf,
        ]);
    }

    /**
     * GET /api/v1/facturas/{cuf}/pdf — descarga el PDF.
     */
    public function pdf(Request $request, string $cuf): Response
    {
        $factura = $this->buscar($request, $cuf);

        abort_if(blank($factura->ruta_pdf) || ! Storage::disk('local')->exists($factura->ruta_pdf), 404);

        return response(Storage::disk('local')->get($factura->ruta_pdf), 200, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * GET /api/v1/facturas/{cuf}/xml — descarga el XML firmado (documento legal).
     */
    public function xml(Request $request, string $cuf): Response
    {
        $factura = $this->buscar($request, $cuf);

        abort_if(blank($factura->xml_firmado), 404);

        return response($factura->xml_firmado, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * Busca una factura de la empresa autenticada por su CUF o falla con 404.
     */
    private function buscar(Request $request, string $cuf): Factura
    {
        return Factura::where('empresa_id', $this->empresa($request)->id)
            ->where('cuf', $cuf)
            ->with('puntoVenta.sucursal')
            ->firstOrFail();
    }

    private function empresa(Request $request): Empresa
    {
        return $request->attributes->get('empresa');
    }
}
