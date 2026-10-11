<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\CufdVencidoException;
use App\Exceptions\FacturaInvalidaException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnularFacturaRequest;
use App\Http\Requests\EmitirFacturaRequest;
use App\Http\Resources\FacturaResource;
use App\Jobs\AnularFacturaEnSiat;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\FacturaAnulada;
use App\Services\Factura\EmisorFactura;
use App\Services\Factura\GeneradorPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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

        $filtros = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'estado' => ['nullable', Rule::in(Factura::ESTADOS)],
            'sucursal' => ['nullable', 'integer', 'min:0'],
            'punto_venta' => ['nullable', 'integer', 'min:0', 'required_with:sucursal'],
            // Para encontrar la venta propia despues de un timeout sin
            // respuesta: es la misma clave de idempotencia.
            'referencia_externa' => ['nullable', 'string', 'max:100'],
        ]);

        $facturas = Factura::where('empresa_id', $empresa->id)
            ->when($filtros['desde'] ?? null, fn ($q, $desde) => $q->whereDate('fecha_emision', '>=', $desde))
            ->when($filtros['hasta'] ?? null, fn ($q, $hasta) => $q->whereDate('fecha_emision', '<=', $hasta))
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->when($filtros['referencia_externa'] ?? null, fn ($q, $referencia) => $q->where('referencia_externa', $referencia))
            ->when(isset($filtros['punto_venta']), fn ($q) => $q->whereHas('puntoVenta', fn ($pv) => $pv
                ->where('codigo_punto_venta', $filtros['punto_venta'])
                ->whereHas('sucursal', fn ($s) => $s->where('codigo_sucursal', $filtros['sucursal']))))
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
        // Se guarda el estado previo: si el SIN rechaza la anulacion, el job la
        // devuelve ahi en vez de dejarla ANULADA de este lado y vigente del otro.
        FacturaAnulada::updateOrCreate(
            ['factura_id' => $factura->id],
            [
                'motivo' => $request->integer('motivo'),
                'anulada_en' => now(),
                'estado' => FacturaAnulada::ESTADO_PENDIENTE,
                'estado_anterior' => $factura->estado,
                'motivo_rechazo' => null,
            ],
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
    public function pdf(Request $request, string $cuf, GeneradorPdf $generador): Response
    {
        $factura = $this->buscar($request, $cuf);

        // El envio al SIN no espera al PDF: si no se pudo generar entonces (o
        // todavia no se envio), se genera a pedido. Toda factura con XML
        // firmado tiene su representacion imprimible.
        if (blank($factura->ruta_pdf) || ! Storage::disk('local')->exists($factura->ruta_pdf)) {
            abort_if(blank($factura->xml_firmado), 404);

            $factura->update(['ruta_pdf' => $generador->generarYGuardar($factura)]);
        }

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
