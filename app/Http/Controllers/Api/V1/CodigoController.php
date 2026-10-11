<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SiatException;
use App\Http\Controllers\Controller;
use App\Models\Cufd;
use App\Models\Cuis;
use App\Models\Empresa;
use App\Models\PuntoVenta;
use App\Services\Siat\GestorCodigos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * CUIS y CUFD de un punto de venta, vistos por el sistema de ventas.
 *
 * La API maneja los codigos sola (renovacion preventiva, reactiva al emitir y
 * diaria del CUIS). Aca el sistema de ventas solo puede VER si estan vigentes
 * y, en una emergencia, forzar un CUFD nuevo.
 *
 * Nunca se devuelven los codigos ni el codigo de control: con ellos un
 * tercero podria armar CUF por su cuenta, y dos emisores sobre el mismo punto
 * de venta rompen el correlativo.
 */
class CodigoController extends Controller
{
    /**
     * Renovaciones forzadas de CUFD permitidas por punto de venta y por hora.
     * Cada una crea un CUFD real en el SIN: un bucle en el sistema del cliente
     * no puede pedir cientos.
     */
    private const RENOVACIONES_POR_HORA = 3;

    /**
     * GET /api/v1/puntos-venta/{sucursal}/{puntoVenta}/codigos
     */
    public function show(Request $request, int $sucursal, int $puntoVenta): JsonResponse
    {
        $punto = PuntoVenta::deEmpresaPorCodigos($this->empresa($request), $sucursal, $puntoVenta);

        $cuis = $punto->cuisVigente();
        $cufd = $punto->cufdVigente();

        return response()->json([
            'exito' => true,
            'sucursal' => $sucursal,
            'punto_venta' => $puntoVenta,
            'registrado_en_siat' => $punto->existeEnElSiat(),
            'cuis' => $this->vigencia($cuis),
            'cufd' => $this->vigencia($cufd),
            // Sin CUFD igual se puede facturar: la emision lo pide en el
            // momento. Lo que impide facturar es no estar registrado.
            'puede_facturar' => $punto->existeEnElSiat(),
        ]);
    }

    /**
     * POST /api/v1/puntos-venta/{sucursal}/{puntoVenta}/cufd — renovacion de
     * emergencia (permiso codigos:renovar).
     */
    public function renovarCufd(Request $request, int $sucursal, int $puntoVenta, GestorCodigos $gestor): JsonResponse
    {
        $punto = PuntoVenta::deEmpresaPorCodigos($this->empresa($request), $sucursal, $puntoVenta);

        if (! $punto->existeEnElSiat()) {
            return response()->json([
                'exito' => false,
                'error' => 'PUNTO_VENTA_NO_REGISTRADO',
                'mensaje' => 'El punto de venta no esta registrado en el SIAT: no puede tener CUFD.',
            ], Response::HTTP_CONFLICT);
        }

        $clave = "renovar-cufd:{$punto->id}";

        if (RateLimiter::tooManyAttempts($clave, self::RENOVACIONES_POR_HORA)) {
            return response()->json([
                'exito' => false,
                'error' => 'DEMASIADAS_RENOVACIONES',
                'mensaje' => 'Se alcanzo el maximo de '.self::RENOVACIONES_POR_HORA.' renovaciones de CUFD por hora para este punto de venta.',
            ], Response::HTTP_TOO_MANY_REQUESTS)->header('Retry-After', RateLimiter::availableIn($clave));
        }

        RateLimiter::hit($clave, 3600);

        try {
            $cufd = $gestor->renovarCufd($punto);
        } catch (SiatException $e) {
            return response()->json([
                'exito' => false,
                'error' => 'SIAT_NO_DISPONIBLE',
                'mensaje' => "El SIAT no entrego un CUFD nuevo: {$e->getMessage()}",
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json([
            'exito' => true,
            'cufd' => $this->vigencia($cufd),
        ], Response::HTTP_CREATED);
    }

    /**
     * @return array{vigente: bool, vence: string|null}
     */
    private function vigencia(Cuis|Cufd|null $codigo): array
    {
        return [
            'vigente' => $codigo !== null,
            'vence' => $codigo?->fecha_vigencia?->toIso8601String(),
        ];
    }

    private function empresa(Request $request): Empresa
    {
        return $request->attributes->get('empresa');
    }
}
