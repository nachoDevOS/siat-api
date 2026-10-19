<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\CufdVencidoException;
use App\Exceptions\FacturaInvalidaException;
use App\Exceptions\SiatException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnularFacturaAdministrativamenteRequest;
use App\Http\Requests\AnularFacturaRequest;
use App\Http\Requests\EmitirFacturaRequest;
use App\Http\Requests\EmitirLoteRequest;
use App\Http\Resources\FacturaResource;
use App\Models\Empresa;
use App\Models\Factura;
use App\Services\Factura\AnuladorFactura;
use App\Services\Factura\EmisorFactura;
use App\Services\Factura\GeneradorPdf;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
     * POST /api/v1/facturas/lote — emite hasta 20 ventas en una peticion.
     *
     * Cada venta se emite por separado, igual que en POST /facturas, y viaja
     * sola al SIN en linea: no es la emision masiva (otra modalidad del SIN).
     * Una venta rechazada no frena a las demas; la respuesta trae el
     * resultado de cada una en el mismo orden. Reintentar el lote es seguro:
     * las que ya salieron vuelven por su referencia_externa.
     */
    public function lote(EmitirLoteRequest $request, EmisorFactura $emisor): JsonResponse
    {
        $empresa = $this->empresa($request);
        $resultados = [];

        foreach ($request->ventas() as $indice => $venta) {
            $resultado = ['indice' => $indice, 'referencia_externa' => $venta['referencia_externa']];

            try {
                $factura = $emisor->emitir($empresa, $venta);
                $resultados[] = $resultado + ['exito' => true, 'factura' => new FacturaResource($factura->load('puntoVenta.sucursal'))];
            } catch (FacturaInvalidaException $e) {
                $resultados[] = $resultado + ['exito' => false, 'error' => 'FACTURA_INVALIDA', 'detalles' => $e->errores];
            } catch (CufdVencidoException $e) {
                $resultados[] = $resultado + ['exito' => false, 'error' => 'SIAT_NO_DISPONIBLE', 'mensaje' => $e->getMessage()];
            } catch (ModelNotFoundException) {
                $resultados[] = $resultado + ['exito' => false, 'error' => 'PUNTO_VENTA_NO_ENCONTRADO', 'mensaje' => "No existe el punto de venta {$venta['punto_venta']} de la sucursal {$venta['sucursal']}."];
            }
        }

        $emitidas = count(array_filter($resultados, fn (array $r) => $r['exito']));

        return response()->json([
            'exito' => $emitidas === count($resultados),
            'emitidas' => $emitidas,
            'rechazadas' => count($resultados) - $emitidas,
            'resultados' => $resultados,
        ]);
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
            // Las notas de credito-debito viven en la misma tabla. Por defecto
            // se listan solo facturas, como antes de que existieran las notas.
            'tipo' => ['nullable', Rule::in(['factura', 'nota', 'todos'])],
        ]);

        $sectorNota = config('siat.codigos.nota_credito_debito.documento_sector');

        $facturas = Factura::where('empresa_id', $empresa->id)
            ->when(($filtros['tipo'] ?? 'factura') === 'factura', fn ($q) => $q->where('codigo_documento_sector', '!=', $sectorNota))
            ->when(($filtros['tipo'] ?? null) === 'nota', fn ($q) => $q->where('codigo_documento_sector', $sectorNota))
            ->when($filtros['desde'] ?? null, fn ($q, $desde) => $q->whereDate('fecha_emision', '>=', $desde))
            ->when($filtros['hasta'] ?? null, fn ($q, $hasta) => $q->whereDate('fecha_emision', '<=', $hasta))
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->when($filtros['referencia_externa'] ?? null, fn ($q, $referencia) => $q->where('referencia_externa', $referencia))
            ->when(isset($filtros['punto_venta']), fn ($q) => $q->whereHas('puntoVenta', fn ($pv) => $pv
                ->where('codigo_punto_venta', $filtros['punto_venta'])
                ->whereHas('sucursal', fn ($s) => $s->where('codigo_sucursal', $filtros['sucursal']))))
            ->with(['puntoVenta.sucursal', 'facturaOrigen', 'anulacion'])
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
    public function anular(AnularFacturaRequest $request, string $cuf, AnuladorFactura $anulador): JsonResponse
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

        if (! $anulador->esAnulable($factura)) {
            return response()->json([
                'exito' => false,
                'error' => 'FACTURA_NO_ANULABLE',
                'mensaje' => $anulador->motivoNoAnulable($factura),
            ], Response::HTTP_CONFLICT);
        }

        $anulador->anular($factura, $request->integer('motivo'));

        return response()->json([
            'exito' => true,
            'mensaje' => 'Anulacion registrada. Pendiente de confirmacion del SIAT.',
            'cuf' => $factura->cuf,
        ]);
    }

    /**
     * POST /api/v1/facturas/{cuf}/revertir-anulacion — la factura anulada
     * vuelve a ser valida ante el SIN. Es sincrono: responde con lo que dijo
     * el SIN. El SIN lo permite una sola vez por factura.
     */
    public function revertirAnulacion(Request $request, string $cuf, AnuladorFactura $anulador): JsonResponse
    {
        $factura = $this->buscar($request, $cuf);

        if (! $anulador->esRevertible($factura)) {
            return response()->json([
                'exito' => false,
                'error' => 'FACTURA_NO_REVERTIBLE',
                'mensaje' => $anulador->motivoNoRevertible($factura),
            ], Response::HTTP_CONFLICT);
        }

        try {
            $anulador->revertir($factura);
        } catch (SiatException $e) {
            // El SIN no respondio o rechazo la reversion: la factura sigue
            // ANULADA y se puede reintentar.
            return response()->json([
                'exito' => false,
                'error' => 'SIAT_NO_REVIRTIO',
                'mensaje' => $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json([
            'exito' => true,
            'mensaje' => 'Anulacion revertida: la factura vuelve a estar vigente ante el SIN.',
            'factura' => new FacturaResource($factura->fresh(['puntoVenta.sucursal', 'facturaOrigen', 'anulacion'])),
        ]);
    }

    /**
     * POST /api/v1/facturas/{cuf}/anulacion-administrativa — registra una
     * anulacion que el SIN ya aprobo por tramite, fuera del plazo del
     * servicio web. No llama al SIAT.
     */
    public function anularAdministrativamente(AnularFacturaAdministrativamenteRequest $request, string $cuf, AnuladorFactura $anulador): JsonResponse
    {
        $factura = $this->buscar($request, $cuf);

        if ($factura->estado === Factura::ESTADO_ANULADA) {
            return response()->json([
                'exito' => true,
                'mensaje' => 'La factura ya estaba anulada.',
                'cuf' => $factura->cuf,
            ]);
        }

        if (! $anulador->admiteAnulacionAdministrativa($factura)) {
            return response()->json([
                'exito' => false,
                'error' => 'FACTURA_NO_ANULABLE',
                'mensaje' => $anulador->tieneNotasVigentes($factura)
                    ? 'La factura tiene notas de credito-debito vigentes: anule primero las notas.'
                    : "Una factura en estado {$factura->estado} no se puede anular.",
            ], Response::HTTP_CONFLICT);
        }

        $anulador->anularAdministrativamente($factura, $request->integer('motivo'), $request->string('resolucion')->toString());

        return response()->json([
            'exito' => true,
            'mensaje' => 'Anulacion administrativa registrada.',
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
            ->with(['puntoVenta.sucursal', 'facturaOrigen', 'anulacion'])
            ->firstOrFail();
    }

    private function empresa(Request $request): Empresa
    {
        return $request->attributes->get('empresa');
    }
}
