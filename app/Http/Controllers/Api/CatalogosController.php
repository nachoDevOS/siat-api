<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiatActividad;
use App\Models\SiatEventoSignificativo;
use App\Models\SiatLeyenda;
use App\Models\SiatMotivoAnulacion;
use App\Services\Siat\SincronizacionService;
use App\Services\InvoiceService;
use App\Models\SiatCuis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SoapFault;

/**
 * Controlador de catálogos SIAT (tablas paramétricas del SIN).
 *
 * Los catálogos se sincronizan desde el SIN y se guardan localmente.
 * El sistema cliente los puede consultar sin llamar al SIN directamente.
 *
 * Endpoints de sincronización (POST — requieren CUIS vigente):
 *   POST /api/v1/catalogos/sync/actividades
 *   POST /api/v1/catalogos/sync/leyendas
 *   POST /api/v1/catalogos/sync/motivos-anulacion
 *   POST /api/v1/catalogos/sync/eventos-significativos
 *   POST /api/v1/catalogos/sync/tipos-documento
 *   POST /api/v1/catalogos/sync/metodos-pago
 *   POST /api/v1/catalogos/sync/unidades-medida
 *   POST /api/v1/catalogos/sync/productos-servicios
 *   POST /api/v1/catalogos/sync/tipos-punto-venta
 *   POST /api/v1/catalogos/sync/tipos-moneda
 *   POST /api/v1/catalogos/sync/todos         (sincroniza todos de una vez)
 *
 * Endpoints de consulta (GET — devuelven la tabla local):
 *   GET /api/v1/catalogos/actividades
 *   GET /api/v1/catalogos/leyendas
 *   GET /api/v1/catalogos/motivos-anulacion
 *   GET /api/v1/catalogos/eventos-significativos
 */
class CatalogosController extends Controller
{
    public function __construct(
        private readonly SincronizacionService $sincronizacion,
        private readonly InvoiceService        $invoiceService,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // CONSULTAS LOCALES
    // ─────────────────────────────────────────────────────────────────────────

    public function actividades(): JsonResponse
    {
        return response()->json([
            'ok'   => true,
            'data' => SiatActividad::orderBy('codigoCaeb')->get(),
        ]);
    }

    public function leyendas(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'codigo_actividad' => 'nullable|string',
        ]);

        $query = SiatLeyenda::query();
        if (!empty($validated['codigo_actividad'])) {
            $query->where('codigoActividad', $validated['codigo_actividad']);
        }

        return response()->json([
            'ok'   => true,
            'data' => $query->get(),
        ]);
    }

    public function motivosAnulacion(): JsonResponse
    {
        return response()->json([
            'ok'   => true,
            'data' => SiatMotivoAnulacion::orderBy('codigoClasificador')->get(),
        ]);
    }

    public function eventosSignificativos(): JsonResponse
    {
        return response()->json([
            'ok'   => true,
            'data' => SiatEventoSignificativo::orderBy('codigoClasificador')->get(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SINCRONIZACIÓN DESDE SIAT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sincroniza todos los catálogos en una sola llamada.
     *
     * Body:
     * { "codigo_sucursal": 0, "codigo_punto_venta": 0 }
     */
    public function syncTodos(Request $request): JsonResponse
    {
        [$codigoSucursal, $codigoPuntoVenta, $cuis] = $this->resolverContexto($request);

        if (!$cuis) {
            return $this->sinContexto();
        }

        $metodos = [
            'actividades'         => 'actividades',
            'leyendas'            => 'leyendas',
            'motivosAnulacion'    => 'motivosAnulacion',
            'eventosSignificativos' => 'eventosSignificativos',
            'tiposDocumento'      => 'tiposDocumento',
            'metodosPago'         => 'metodosPago',
            'unidadesMedida'      => 'unidadesMedida',
            'tiposPuntoVenta'     => 'tiposPuntoVenta',
            'tiposMoneda'         => 'tiposMoneda',
        ];

        $resultados = [];
        foreach ($metodos as $clave => $metodo) {
            $res = $this->sincronizacion->$metodo($codigoSucursal, $codigoPuntoVenta, $cuis);
            $resultados[$clave] = ($res instanceof SoapFault) ? 'error: ' . $res->getMessage() : 'ok';
        }

        // productosServicios puede ser lento; se sincroniza al final
        $res = $this->sincronizacion->productosServicios($codigoSucursal, $codigoPuntoVenta, $cuis);
        $resultados['productosServicios'] = ($res instanceof SoapFault) ? 'error: ' . $res->getMessage() : 'ok';

        return response()->json([
            'ok'         => true,
            'resultados' => $resultados,
        ]);
    }

    /**
     * Sincroniza solo las actividades económicas desde SIAT.
     * Body: { "codigo_sucursal": 0, "codigo_punto_venta": 0 }
     */
    public function syncActividades(Request $request): JsonResponse
    {
        [$codigoSucursal, $codigoPuntoVenta, $cuis] = $this->resolverContexto($request);
        if (!$cuis) return $this->sinContexto();

        $res = $this->sincronizacion->actividades($codigoSucursal, $codigoPuntoVenta, $cuis);
        return $this->respuestaSincronizacion($res, 'actividades');
    }

    public function syncLeyendas(Request $request): JsonResponse
    {
        [$codigoSucursal, $codigoPuntoVenta, $cuis] = $this->resolverContexto($request);
        if (!$cuis) return $this->sinContexto();

        $res = $this->sincronizacion->leyendas($codigoSucursal, $codigoPuntoVenta, $cuis);
        return $this->respuestaSincronizacion($res, 'leyendas');
    }

    public function syncMotivosAnulacion(Request $request): JsonResponse
    {
        [$codigoSucursal, $codigoPuntoVenta, $cuis] = $this->resolverContexto($request);
        if (!$cuis) return $this->sinContexto();

        $res = $this->sincronizacion->motivosAnulacion($codigoSucursal, $codigoPuntoVenta, $cuis);
        return $this->respuestaSincronizacion($res, 'motivosAnulacion');
    }

    public function syncEventosSignificativos(Request $request): JsonResponse
    {
        [$codigoSucursal, $codigoPuntoVenta, $cuis] = $this->resolverContexto($request);
        if (!$cuis) return $this->sinContexto();

        $res = $this->sincronizacion->eventosSignificativos($codigoSucursal, $codigoPuntoVenta, $cuis);
        return $this->respuestaSincronizacion($res, 'eventosSignificativos');
    }

    public function syncTiposDocumento(Request $request): JsonResponse
    {
        [$codigoSucursal, $codigoPuntoVenta, $cuis] = $this->resolverContexto($request);
        if (!$cuis) return $this->sinContexto();

        $res = $this->sincronizacion->tiposDocumento($codigoSucursal, $codigoPuntoVenta, $cuis);
        return $this->respuestaSincronizacion($res, 'tiposDocumento');
    }

    public function syncMetodosPago(Request $request): JsonResponse
    {
        [$codigoSucursal, $codigoPuntoVenta, $cuis] = $this->resolverContexto($request);
        if (!$cuis) return $this->sinContexto();

        $res = $this->sincronizacion->metodosPago($codigoSucursal, $codigoPuntoVenta, $cuis);
        return $this->respuestaSincronizacion($res, 'metodosPago');
    }

    public function syncUnidadesMedida(Request $request): JsonResponse
    {
        [$codigoSucursal, $codigoPuntoVenta, $cuis] = $this->resolverContexto($request);
        if (!$cuis) return $this->sinContexto();

        $res = $this->sincronizacion->unidadesMedida($codigoSucursal, $codigoPuntoVenta, $cuis);
        return $this->respuestaSincronizacion($res, 'unidadesMedida');
    }

    public function syncProductosServicios(Request $request): JsonResponse
    {
        [$codigoSucursal, $codigoPuntoVenta, $cuis] = $this->resolverContexto($request);
        if (!$cuis) return $this->sinContexto();

        $res = $this->sincronizacion->productosServicios($codigoSucursal, $codigoPuntoVenta, $cuis);
        return $this->respuestaSincronizacion($res, 'productosServicios');
    }

    public function syncTiposPuntoVenta(Request $request): JsonResponse
    {
        [$codigoSucursal, $codigoPuntoVenta, $cuis] = $this->resolverContexto($request);
        if (!$cuis) return $this->sinContexto();

        $res = $this->sincronizacion->tiposPuntoVenta($codigoSucursal, $codigoPuntoVenta, $cuis);
        return $this->respuestaSincronizacion($res, 'tiposPuntoVenta');
    }

    public function syncTiposMoneda(Request $request): JsonResponse
    {
        [$codigoSucursal, $codigoPuntoVenta, $cuis] = $this->resolverContexto($request);
        if (!$cuis) return $this->sinContexto();

        $res = $this->sincronizacion->tiposMoneda($codigoSucursal, $codigoPuntoVenta, $cuis);
        return $this->respuestaSincronizacion($res, 'tiposMoneda');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS PRIVADOS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resuelve el contexto SIAT desde el body del request y retorna [sucursal, pv, cuis].
     * Si no hay CUIS, retorna [x, x, null].
     */
    private function resolverContexto(Request $request): array
    {
        $validated = $request->validate([
            'codigo_sucursal'    => 'required|integer|min:0',
            'codigo_punto_venta' => 'required|integer|min:0',
        ]);

        $codigoSucursal   = (int) $validated['codigo_sucursal'];
        $codigoPuntoVenta = (int) $validated['codigo_punto_venta'];

        try {
            $ctx  = $this->invoiceService->getContext($codigoSucursal, $codigoPuntoVenta);
            $cuis = $ctx['cuis'];
        } catch (\Exception) {
            $cuis = null;
        }

        return [$codigoSucursal, $codigoPuntoVenta, $cuis];
    }

    private function sinContexto(): JsonResponse
    {
        return response()->json([
            'ok'      => false,
            'mensaje' => 'Sin CUIS/CUFD vigente. Sincronize los códigos primero.',
        ], 503);
    }

    private function respuestaSincronizacion(mixed $res, string $catalogo): JsonResponse
    {
        if ($res instanceof SoapFault) {
            return response()->json([
                'ok'       => false,
                'catalogo' => $catalogo,
                'mensaje'  => $res->getMessage(),
            ], 503);
        }

        return response()->json([
            'ok'       => true,
            'catalogo' => $catalogo,
            'respuesta' => $res,
        ]);
    }
}
