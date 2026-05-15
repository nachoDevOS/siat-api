<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiatCufd;
use App\Models\SiatCuis;
use App\Services\InvoiceService;
use App\Services\Siat\CodigosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SoapFault;

/**
 * Controlador de códigos SIAT (CUIS, CUFD, NIT, ping).
 *
 * Endpoints:
 *   GET  /api/v1/codigos/ping          — verificar comunicación con SIAT
 *   POST /api/v1/codigos/sync          — sincronizar CUIS/CUFD para una sucursal/PV
 *   GET  /api/v1/codigos/context       — obtener contexto activo (CUIS+CUFD vigentes)
 *   POST /api/v1/codigos/verificar-nit — verificar si un NIT está en el padrón del SIN
 */
class CodigosController extends Controller
{
    public function __construct(
        private readonly CodigosService $codigos,
        private readonly InvoiceService $invoiceService,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Verifica que el SIN responda.
     * No requiere parámetros.
     *
     * Respuesta:
     *   { "transaccion": true, "mensajesList": [...] }
     */
    public function ping(): JsonResponse
    {
        $res = $this->codigos->verificarComunicacion();

        if ($res instanceof SoapFault) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'Sin conexión con SIAT: ' . $res->getMessage(),
            ], 503);
        }

        return response()->json([
            'ok'          => true,
            'transaccion' => $res->RespuestaServicioFacturacion->transaccion ?? ($res->transaccion ?? null),
            'mensajes'    => $res->RespuestaServicioFacturacion->mensajesList ?? ($res->mensajesList ?? null),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sincroniza CUIS y/o CUFD con el SIN para la sucursal/PV indicados.
     * Solo renueva los códigos vencidos.
     *
     * Body:
     *   { "codigo_sucursal": 0, "codigo_punto_venta": 0 }
     *
     * Respuesta exitosa:
     *   { "ok": true, "cuis": "...", "cufd": "...", "cufd_vigencia": "..." }
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'codigo_sucursal'    => 'required|integer|min:0',
            'codigo_punto_venta' => 'required|integer|min:0',
        ]);

        $codigoSucursal   = (int) $validated['codigo_sucursal'];
        $codigoPuntoVenta = (int) $validated['codigo_punto_venta'];

        $activeCuis = SiatCuis::active($codigoSucursal, $codigoPuntoVenta)->first();
        $activeCufd = SiatCufd::active($codigoSucursal, $codigoPuntoVenta)->first();

        // Si ambos están vigentes, no hace ninguna llamada al SIN
        $cuisVigente = !$this->invoiceService->isExpired($activeCuis);
        $cufdVigente = !$this->invoiceService->isExpired($activeCufd);

        if ($cuisVigente && $cufdVigente) {
            return response()->json([
                'ok'           => true,
                'sincronizado' => false,
                'mensaje'      => 'CUIS y CUFD vigentes. No se realizó ninguna renovación.',
                'cuis'         => $activeCuis->codigo,
                'cufd'         => $activeCufd->codigo,
                'cufd_vigencia' => $activeCufd->fechaVigencia,
            ]);
        }

        $result = $this->invoiceService->syncCodes(
            $codigoSucursal,
            $codigoPuntoVenta,
            $cuisVigente ? $activeCuis : null,
            $cufdVigente ? $activeCufd : null,
        );

        if (!$result) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'Sin conexión con SIAT. No se pudieron renovar los códigos.',
            ], 503);
        }

        // Re-fetch para responder con los nuevos datos
        $cuis = SiatCuis::active($codigoSucursal, $codigoPuntoVenta)->first();
        $cufd = SiatCufd::active($codigoSucursal, $codigoPuntoVenta)->first();

        return response()->json([
            'ok'           => true,
            'sincronizado' => true,
            'cuis'         => $cuis?->codigo,
            'cufd'         => $cufd?->codigo,
            'cufd_vigencia' => $cufd?->fechaVigencia,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retorna el contexto SIAT vigente para una sucursal/PV.
     * Si algún código vence, lo renueva automáticamente.
     *
     * Query params:
     *   ?codigo_sucursal=0&codigo_punto_venta=0
     *
     * Respuesta:
     *   { "ok": true, "cuis": "...", "cufd": "...", "cufd_vigencia": "...", "cufd_direccion": "..." }
     */
    public function context(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'codigo_sucursal'    => 'required|integer|min:0',
            'codigo_punto_venta' => 'required|integer|min:0',
        ]);

        try {
            $ctx = $this->invoiceService->getContext(
                (int) $validated['codigo_sucursal'],
                (int) $validated['codigo_punto_venta'],
            );
        } catch (\Exception $e) {
            return response()->json([
                'ok'      => false,
                'mensaje' => $e->getMessage(),
            ], 503);
        }

        /** @var \App\Models\SiatCufd $cufd */
        $cufd = $ctx['cufd'];

        return response()->json([
            'ok'               => true,
            'cuis'             => $ctx['cuis'],
            'cufd'             => $cufd->codigo,
            'cufd_vigencia'    => $cufd->fechaVigencia,
            'cufd_direccion'   => $cufd->direccion,
            'cufd_control'     => $cufd->codigoControl,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Verifica si un NIT está habilitado en el padrón del SIN.
     * Solo aplica cuando codigoTipoDocumentoIdentidad = 5 (NIT).
     *
     * Body:
     *   {
     *     "codigo_sucursal": 0,
     *     "nit": 123456789
     *   }
     *
     * El CUIS se resuelve automáticamente del registro activo para la sucursal dada.
     * Si no hay CUIS vigente, retorna 503.
     */
    public function verificarNit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'codigo_sucursal' => 'required|integer|min:0',
            'nit'             => 'required',
        ]);

        $codigoSucursal = (int) $validated['codigo_sucursal'];

        // Buscar el CUIS vigente de la sucursal (PV 0 es el PV administrativo)
        $cuisRecord = SiatCuis::active($codigoSucursal, 0)->first()
            ?? SiatCuis::where('status', 1)->where('codigo_sucursal', $codigoSucursal)->latest()->first();

        if (!$cuisRecord || $this->invoiceService->isExpired($cuisRecord)) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'Sin CUIS vigente para la sucursal. Sincronize primero.',
            ], 503);
        }

        $res = $this->codigos->verificarNit($codigoSucursal, $cuisRecord->codigo, $validated['nit']);

        if ($res instanceof SoapFault) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'Error SOAP: ' . $res->getMessage(),
            ], 503);
        }

        $resp = $res->RespuestaVerificarNit ?? $res;

        return response()->json([
            'ok'          => true,
            'transaccion' => $resp->transaccion ?? null,
            'mensajes'    => $resp->mensajesList ?? null,
        ]);
    }
}
