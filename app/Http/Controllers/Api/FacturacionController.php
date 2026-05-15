<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Controlador de facturación SIAT (emisión, anulación, reversión, estado).
 *
 * Endpoints:
 *   POST /api/v1/facturas            — emitir factura (en línea o contingencia)
 *   GET  /api/v1/facturas/{id}       — consultar factura local por ID
 *   POST /api/v1/facturas/{id}/anular       — anular una factura en SIAT
 *   POST /api/v1/facturas/{id}/revertir     — revertir la anulación de una factura
 *   GET  /api/v1/facturas/{id}/estado       — verificar estado en SIAT por su CUF
 */
class FacturacionController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Emite una factura en línea (o la guarda en contingencia si SIAT no responde).
     *
     * Body requerido (cabecera de la factura):
     * {
     *   "codigo_sucursal":      0,
     *   "codigo_punto_venta":   0,
     *   "external_id":          "uuid-opcional",   // ID externo del sistema cliente
     *   "nitEmisor":            123456789,          // se toma de config si se omite
     *   "razonSocialEmisor":    "Mi Empresa SRL",  // config si se omite
     *   "municipio":            "La Paz",           // config si se omite
     *   "telefono":             "70000000",         // config si se omite
     *   "numeroDocumento":      12345678,           // del comprador
     *   "complemento":          null,               // 2 dígitos, nullable
     *   "codigoTipoDocumentoIdentidad": 5,          // 1-7 según catálogo
     *   "nombreRazonSocial":    "Juan Perez",
     *   "codigoMetodoPago":     1,                  // catálogo siat_metodo_pagos
     *   "montoTotal":           100.00,
     *   "montoTotalSujetoIva":  100.00,
     *   "descuentoAdicional":   0,
     *   "codigoMoneda":         1,                  // 1=BOB
     *   "tipoCambio":           1,
     *   "codigoActividad":      "47110",            // codigoCaeb
     *   "leyenda":              "Ley 453...",       // texto de la leyenda aleatoria
     *   "usuario":              "cajero01",
     *   "detalles": [
     *     {
     *       "actividadEconomica":     "47110",
     *       "codigoProductoSin":      99900,        // código SIN del producto
     *       "codigoProducto":         "P001",       // código interno
     *       "descripcion":            "Ibuprofeno 400mg",
     *       "cantidad":               2,
     *       "unidadMedida":           58,           // catálogo siat_unidad_medidas
     *       "precioUnitario":         50.00,
     *       "montoDescuento":         0,
     *       "subTotal":               100.00
     *     }
     *   ]
     * }
     *
     * Respuesta exitosa:
     * {
     *   "ok": true,
     *   "invoice_id": 1,
     *   "cuf": "...",
     *   "nroFactura": 1001,
     *   "codigoEstado": "908",
     *   "codigoDescripcion": "VALIDADA",
     *   "contingencia": false
     * }
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'codigo_sucursal'               => 'required|integer|min:0',
            'codigo_punto_venta'            => 'required|integer|min:0',
            'external_id'                   => 'nullable|string|max:255',

            // Cabecera de la factura
            'nitEmisor'                     => 'nullable|integer',
            'razonSocialEmisor'             => 'nullable|string|max:255',
            'municipio'                     => 'nullable|string|max:255',
            'telefono'                      => 'nullable|string|max:20',
            'numeroDocumento'               => 'required',
            'complemento'                   => 'nullable|string|max:2',
            'codigoTipoDocumentoIdentidad'  => 'required|integer',
            'nombreRazonSocial'             => 'required|string|max:255',
            'codigoMetodoPago'              => 'required|integer',
            'montoTotal'                    => 'required|numeric|min:0',
            'montoTotalSujetoIva'           => 'required|numeric|min:0',
            'descuentoAdicional'            => 'nullable|numeric|min:0',
            'codigoExcepcion'               => 'nullable|integer|min:0|max:1',
            'codigoCliente'                 => 'nullable|string|max:255',
            'numeroTarjeta'                 => 'nullable|string|max:50',
            'montoTotalMoneda'              => 'nullable|numeric|min:0',
            'montoGiftCard'                 => 'nullable|numeric|min:0',
            'codigoMoneda'                  => 'nullable|integer',
            'tipoCambio'                    => 'nullable|numeric|min:0',
            'codigoActividad'               => 'required|string',
            'leyenda'                       => 'nullable|string|max:500',
            'usuario'                       => 'nullable|string|max:100',

            // Detalles
            'detalles'                      => 'required|array|min:1',
            'detalles.*.actividadEconomica' => 'required|string',
            'detalles.*.codigoProductoSin'  => 'required|integer',
            'detalles.*.codigoProducto'     => 'required|string',
            'detalles.*.descripcion'        => 'required|string',
            'detalles.*.cantidad'           => 'required|numeric|min:0',
            'detalles.*.unidadMedida'       => 'required|integer',
            'detalles.*.precioUnitario'     => 'required|numeric|min:0',
            'detalles.*.montoDescuento'     => 'nullable|numeric|min:0',
            'detalles.*.subTotal'           => 'required|numeric|min:0',
        ]);

        $codigoSucursal   = (int) $validated['codigo_sucursal'];
        $codigoPuntoVenta = (int) $validated['codigo_punto_venta'];

        // Separar cabecera y detalles
        $cabecera = collect($validated)->except(['codigo_sucursal', 'codigo_punto_venta', 'external_id', 'detalles'])->toArray();
        $detalles = $validated['detalles'];

        // Completar campos opcionales con valores de config
        $cabecera['nitEmisor']          ??= (int) config('siat.nit');
        $cabecera['razonSocialEmisor']  ??= config('siat.razon_social');
        $cabecera['municipio']          ??= config('siat.municipio');
        $cabecera['telefono']           ??= config('siat.telefono');
        $cabecera['descuentoAdicional'] ??= 0;
        $cabecera['codigoMoneda']       ??= 1;  // BOB
        $cabecera['tipoCambio']         ??= 1;
        $cabecera['usuario']            ??= 'API';

        try {
            $invoice = $this->invoiceService->emit(
                $cabecera,
                $detalles,
                $codigoSucursal,
                $codigoPuntoVenta,
                $validated['external_id'] ?? null,
            );
        } catch (\Exception $e) {
            return response()->json([
                'ok'      => false,
                'mensaje' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok'                => true,
            'invoice_id'        => $invoice->id,
            'cuf'               => $invoice->cuf,
            'nroFactura'        => $invoice->nroFactura,
            'codigoEstado'      => $invoice->codigoEstado,
            'codigoDescripcion' => $invoice->codigoDescripcion,
            'contingencia'      => $invoice->codigoEstado === '902',
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retorna los datos de una factura local por su ID.
     */
    public function show(int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        return response()->json([
            'ok'      => true,
            'invoice' => $invoice,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Anula una factura en SIAT.
     *
     * Body:
     * {
     *   "codigo_motivo": 1    // de siat_motivos_anulacion.codigoClasificador
     * }
     *
     * Condiciones de rechazo:
     * - La factura tiene reversed = true (ya fue revertida una vez)
     * - La factura no existe localmente
     * - SIAT no responde
     */
    public function anular(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'codigo_motivo' => 'required|integer|min:1',
        ]);

        $invoice = Invoice::findOrFail($id);

        if ($invoice->reversed) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'La factura ya fue revertida y no puede volver a anularse.',
            ], 422);
        }

        try {
            $res = $this->invoiceService->anular($invoice, (int) $validated['codigo_motivo']);
        } catch (\Exception $e) {
            return response()->json([
                'ok'      => false,
                'mensaje' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok'       => true,
            'respuesta' => $res,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Revierte la anulación de una factura en SIAT.
     * Solo se puede revertir una vez; después el campo reversed=true bloquea nuevos intentos.
     */
    public function revertir(int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        if ($invoice->reversed) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'La anulación de esta factura ya fue revertida anteriormente.',
            ], 422);
        }

        try {
            $res = $this->invoiceService->revertirAnulacion($invoice);
        } catch (\Exception $e) {
            return response()->json([
                'ok'      => false,
                'mensaje' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok'       => true,
            'respuesta' => $res,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Verifica el estado de una factura directamente en SIAT por su CUF.
     * Útil para reconciliar facturas en estado 902.
     */
    public function estado(int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        try {
            $ctx = $this->invoiceService->getContextFromXml($invoice);
        } catch (\Exception $e) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'No se pudo resolver el contexto de la factura: ' . $e->getMessage(),
            ], 422);
        }

        $siatService = app(\App\Services\Siat\FacturacionService::class);

        $res = $siatService->verificacionEstado(
            $ctx['cufd_codigo'],
            $invoice->cuf,
            $ctx['cuis'],
            $invoice->codigo_sucursal,
            $invoice->codigo_punto_venta,
        );

        if ($res instanceof \SoapFault) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'Error SOAP: ' . $res->getMessage(),
            ], 503);
        }

        $respuesta = $res->RespuestaServicioFacturacion ?? $res;

        return response()->json([
            'ok'                => true,
            'invoice_id'        => $invoice->id,
            'cuf'               => $invoice->cuf,
            'codigoEstado'      => $respuesta->codigoEstado ?? null,
            'codigoDescripcion' => $respuesta->codigoDescripcion ?? null,
            'transaccion'       => $respuesta->transaccion ?? null,
            'mensajes'          => $respuesta->mensajesList ?? null,
        ]);
    }
}
