<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoicePackage;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controlador de paquetes de contingencia SIAT.
 *
 * Flujo de contingencia:
 *   1. POST /api/v1/paquetes/reconciliar  — verifica facturas 902 que ya están en SIAT
 *   2. POST /api/v1/paquetes/enviar       — registra evento y envía el paquete tar.gz
 *   3. GET  /api/v1/paquetes/{id}/estado  — consulta si el paquete fue procesado
 *
 * Otros:
 *   GET /api/v1/paquetes/pendientes       — lista facturas en estado 902 de una sucursal/PV
 *   GET /api/v1/paquetes                  — lista paquetes enviados
 */
class PaquetesController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Lista las facturas en estado 902 (contingencia pendiente) de una sucursal/PV.
     *
     * Query params:
     *   ?codigo_sucursal=0&codigo_punto_venta=0
     *   &fecha_inicio=2026-01-01&fecha_fin=2026-01-31  (opcionales, filtros de emisión)
     */
    public function pendientes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'codigo_sucursal'    => 'required|integer|min:0',
            'codigo_punto_venta' => 'required|integer|min:0',
            'fecha_inicio'       => 'nullable|date',
            'fecha_fin'          => 'nullable|date',
        ]);

        $query = Invoice::where('codigoEstado', '902')
            ->whereNull('invoice_package_id')
            ->where('codigo_sucursal', (int) $validated['codigo_sucursal'])
            ->where('codigo_punto_venta', (int) $validated['codigo_punto_venta']);

        if (!empty($validated['fecha_inicio'])) {
            $query->whereDate('fechaEmision', '>=', $validated['fecha_inicio']);
        }
        if (!empty($validated['fecha_fin'])) {
            $query->whereDate('fechaEmision', '<=', $validated['fecha_fin']);
        }

        $facturas = $query->orderBy('fechaEmision')->get(['id', 'cuf', 'nroFactura', 'fechaEmision', 'montoTotal', 'codigoEstado']);

        return response()->json([
            'ok'       => true,
            'total'    => $facturas->count(),
            'facturas' => $facturas,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Preverifica facturas 902 contra SIAT antes de enviar el paquete.
     * Actualiza a 908 las que SIAT ya tiene registradas (falsos pendientes por timeout).
     *
     * Body:
     * {
     *   "codigo_sucursal":    0,
     *   "codigo_punto_venta": 0,
     *   "fecha_inicio":       "2026-01-01",   // opcional
     *   "fecha_fin":          "2026-01-31"    // opcional
     * }
     *
     * Respuesta:
     * {
     *   "ok": true,
     *   "verificadas": 5,
     *   "corregidas":  2,    // actualizadas a 908
     *   "pendientes":  3,    // siguen en 902 (contingencia real)
     *   "detalle": [...]
     * }
     */
    public function reconciliar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'codigo_sucursal'    => 'required|integer|min:0',
            'codigo_punto_venta' => 'required|integer|min:0',
            'fecha_inicio'       => 'nullable|date',
            'fecha_fin'          => 'nullable|date',
        ]);

        try {
            $resultado = $this->invoiceService->reconcilePending(
                (int) $validated['codigo_sucursal'],
                (int) $validated['codigo_punto_venta'],
                $validated['fecha_inicio'] ?? null,
                $validated['fecha_fin'] ?? null,
            );
        } catch (\Exception $e) {
            return response()->json([
                'ok'      => false,
                'mensaje' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok'         => true,
            'verificadas' => $resultado['verificadas'] ?? 0,
            'corregidas'  => $resultado['corregidas'] ?? 0,
            'pendientes'  => $resultado['pendientes'] ?? 0,
            'detalle'     => $resultado['detalle'] ?? [],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Registra el evento significativo en SIAT y envía el paquete de contingencia.
     *
     * Flujo interno:
     *   1. Reconciliar 902s (preverificación).
     *   2. Si quedan 902s reales, registrar evento significativo.
     *   3. Construir tar.gz con los XMLs.
     *   4. Llamar a recepcionPaquete en SIAT.
     *   5. Llamar a validacionPaquete para confirmar.
     *
     * Body:
     * {
     *   "codigo_sucursal":           0,
     *   "codigo_punto_venta":        0,
     *   "fecha_inicio":              "2026-01-01T00:00:00",  // inicio del período de contingencia
     *   "fecha_fin":                 "2026-01-01T23:59:59",  // fin del período de contingencia
     *   "codigo_evento_significativo": 1,                    // catálogo siat_eventos_significativos
     *   "descripcion_evento":        "Corte de internet"
     * }
     *
     * Respuesta exitosa:
     * {
     *   "ok": true,
     *   "package_id": 1,
     *   "codigoRecepcion": "...",
     *   "codigoEstado": "908",
     *   "facturas_incluidas": 10
     * }
     */
    public function enviar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'codigo_sucursal'              => 'required|integer|min:0',
            'codigo_punto_venta'           => 'required|integer|min:0',
            'fecha_inicio'                 => 'required|date',
            'fecha_fin'                    => 'required|date|after_or_equal:fecha_inicio',
            'codigo_evento_significativo'  => 'required|integer|min:1',
            'descripcion_evento'           => 'required|string|max:255',
        ]);

        $codigoSucursal   = (int) $validated['codigo_sucursal'];
        $codigoPuntoVenta = (int) $validated['codigo_punto_venta'];

        // 1. Resolver contexto SIAT
        try {
            $ctx = $this->invoiceService->getContext($codigoSucursal, $codigoPuntoVenta);
        } catch (\Exception $e) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'Sin contexto SIAT: ' . $e->getMessage(),
            ], 503);
        }

        $cuis = $ctx['cuis'];
        /** @var \App\Models\SiatCufd $cufdModel */
        $cufdModel = $ctx['cufd'];
        $cufd      = $cufdModel->codigo;

        // 2. Preverificación de 902s
        try {
            $reconciliacion = $this->invoiceService->reconcilePending(
                $codigoSucursal,
                $codigoPuntoVenta,
                $validated['fecha_inicio'],
                $validated['fecha_fin'],
            );
        } catch (\Exception $e) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'Error en preverificación: ' . $e->getMessage(),
            ], 422);
        }

        // Si tras reconciliar no quedan pendientes reales, no hace falta paquete
        if (($reconciliacion['pendientes'] ?? 0) === 0) {
            return response()->json([
                'ok'         => true,
                'sin_paquete' => true,
                'mensaje'    => 'No quedan facturas 902 pendientes. No se generó paquete.',
                'corregidas' => $reconciliacion['corregidas'] ?? 0,
            ]);
        }

        // 3. Registrar evento significativo en SIAT
        $evento = (object) [
            'codigoClasificador'    => $validated['codigo_evento_significativo'],
            'descripcion'           => $validated['descripcion_evento'],
            'fechaHoraInicioEvento' => $validated['fecha_inicio'],
            'fechaHoraFinEvento'    => $validated['fecha_fin'],
        ];

        $operacionesService = app(\App\Services\Siat\OperacionesService::class);
        $resEvento = $operacionesService->registroEventoSignificativo(
            $evento,
            $cuis,
            $cufd,
            $cufd,   // cufdEvento = mismo cufd en esta implementación
            $codigoSucursal,
            $codigoPuntoVenta,
        );

        if ($resEvento instanceof \SoapFault) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'Error al registrar evento significativo: ' . $resEvento->getMessage(),
            ], 503);
        }

        $codigoEvento = $resEvento->RespuestaEventoSignificativo->codigoRecepcionEventoSignificativo
            ?? ($resEvento->codigoRecepcionEventoSignificativo ?? null);

        if (!$codigoEvento) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'SIAT no retornó codigoRecepcionEventoSignificativo.',
                'respuesta' => $resEvento,
            ], 422);
        }

        // 4. Enviar paquete
        try {
            $resultado = $this->invoiceService->sendPackage(
                $codigoSucursal,
                $codigoPuntoVenta,
                $cuis,
                $cufd,   // string código CUFD
                (int) $codigoEvento,
                null,
                [
                    'start' => \Carbon\Carbon::parse($validated['fecha_inicio']),
                    'end'   => \Carbon\Carbon::parse($validated['fecha_fin']),
                ],
            );
        } catch (\Exception $e) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'Error al enviar paquete: ' . $e->getMessage(),
            ], 422);
        }

        $respuesta = $resultado->RespuestaServicioFacturacion ?? $resultado;

        return response()->json([
            'ok'                 => true,
            'codigoRecepcion'    => $respuesta->codigoRecepcion ?? null,
            'codigoEstado'       => $respuesta->codigoEstado ?? null,
            'codigoDescripcion'  => $respuesta->codigoDescripcion ?? null,
            'transaccion'        => $respuesta->transaccion ?? null,
            'mensajes'           => $respuesta->mensajesList ?? null,
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Consulta el estado de validación de un paquete enviado en SIAT.
     * Llamar después de enviar para confirmar procesamiento final.
     */
    public function estado(int $id): JsonResponse
    {
        $package = InvoicePackage::findOrFail($id);

        if (!$package->codigoRecepcion) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'El paquete no tiene codigoRecepcion (no fue enviado aún).',
            ], 422);
        }

        try {
            $ctx = $this->invoiceService->getContext(
                $package->codigo_sucursal,
                $package->codigo_punto_venta,
            );
        } catch (\Exception $e) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'Sin contexto SIAT: ' . $e->getMessage(),
            ], 503);
        }

        /** @var \App\Models\SiatCufd $cufdModel */
        $cufdModel = $ctx['cufd'];

        $siatService = app(\App\Services\Siat\FacturacionService::class);
        $res = $siatService->validacionPaquete(
            $package->codigoRecepcion,
            $cufdModel->codigo,
            $ctx['cuis'],
            $package->codigo_sucursal,
            $package->codigo_punto_venta,
        );

        if ($res instanceof \SoapFault) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'Error SOAP: ' . $res->getMessage(),
            ], 503);
        }

        $respuesta = $res->RespuestaServicioFacturacion ?? $res;

        // Actualizar estado local del paquete si SIAT ya confirmó
        if (isset($respuesta->codigoEstado) && $respuesta->codigoEstado !== $package->codigoEstado) {
            $package->update([
                'codigoEstado'      => $respuesta->codigoEstado,
                'codigoDescripcion' => $respuesta->codigoDescripcion ?? $package->codigoDescripcion,
                'transaccion'       => $respuesta->transaccion ?? $package->transaccion,
            ]);
        }

        return response()->json([
            'ok'                => true,
            'package_id'        => $package->id,
            'codigoRecepcion'   => $package->codigoRecepcion,
            'codigoEstado'      => $respuesta->codigoEstado ?? $package->codigoEstado,
            'codigoDescripcion' => $respuesta->codigoDescripcion ?? $package->codigoDescripcion,
            'transaccion'       => $respuesta->transaccion ?? null,
            'mensajes'          => $respuesta->mensajesList ?? null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Lista los paquetes enviados, opcionalmente filtrados por sucursal/PV.
     *
     * Query params:
     *   ?codigo_sucursal=0&codigo_punto_venta=0&per_page=20
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'codigo_sucursal'    => 'nullable|integer|min:0',
            'codigo_punto_venta' => 'nullable|integer|min:0',
            'per_page'           => 'nullable|integer|min:1|max:100',
        ]);

        $query = InvoicePackage::withCount('invoices')->latest();

        if (isset($validated['codigo_sucursal'])) {
            $query->where('codigo_sucursal', (int) $validated['codigo_sucursal']);
        }
        if (isset($validated['codigo_punto_venta'])) {
            $query->where('codigo_punto_venta', (int) $validated['codigo_punto_venta']);
        }

        $paquetes = $query->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'ok'      => true,
            'data'    => $paquetes,
        ]);
    }
}
