<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SiatException;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\RespuestaSiat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/nit/{nit}/verificar — pregunta al SIN si un NIT existe y esta
 * activo, ANTES de vender.
 *
 * Es lo que le permite al sistema de ventas decidir el codigo_excepcion: una
 * factura a un NIT que el SIN no reconoce se rechaza, salvo que vaya con
 * codigo_excepcion = 1. Mismo criterio que el sistema que factura en
 * produccion: el NIT es valido cuando el SIN responde transaccion = true.
 */
class NitController extends Controller
{
    /**
     * Minutos que se recuerda la respuesta del SIN para un NIT: una caja
     * suele verificar el mismo cliente varias veces al dia.
     */
    private const CACHE_MINUTOS = 60;

    public function verificar(Request $request, string $nit, FabricaServicios $fabrica): JsonResponse
    {
        /** @var Empresa $empresa */
        $empresa = $request->attributes->get('empresa');

        if (preg_match('/^\d{1,20}$/', $nit) !== 1) {
            return response()->json([
                'exito' => false,
                'error' => 'NIT_INVALIDO',
                'mensaje' => 'El NIT debe tener solo digitos (hasta 20).',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $puntoVenta = $empresa->puntoVentaConCuisVigente();

        if ($puntoVenta === null) {
            return $this->noDisponible('Ningun punto de venta de la empresa tiene CUIS vigente para consultar al SIN.');
        }

        // Por ambiente: el piloto y produccion son padrones distintos.
        $clave = "siat:nit:{$empresa->codigo_ambiente}:{$nit}";

        try {
            $resultado = Cache::remember($clave, now()->addMinutes(self::CACHE_MINUTOS), function () use ($fabrica, $empresa, $nit, $puntoVenta) {
                $respuesta = RespuestaSiat::desde(
                    $fabrica->codigos($empresa)->verificarNit(
                        $nit,
                        $puntoVenta->cuisVigente()->codigo,
                        (int) $puntoVenta->sucursal->codigo_sucursal,
                    ),
                    'RespuestaVerificarNit',
                );

                return [
                    'valido' => $respuesta->aceptada,
                    'mensaje' => $respuesta->mensajes === [] ? null : $respuesta->motivo(),
                ];
            });
        } catch (SiatException $e) {
            // Una falla de comunicacion no se cachea: no es una respuesta.
            return $this->noDisponible("El SIAT no respondio la verificacion: {$e->getMessage()}");
        }

        return response()->json([
            'exito' => true,
            'nit' => $nit,
            'valido' => $resultado['valido'],
            'mensaje' => $resultado['mensaje'],
            // Lo que corresponde mandar en POST /facturas si el comprador usa
            // este NIT (tipo de documento 5).
            'codigo_excepcion' => $resultado['valido'] ? 0 : 1,
        ]);
    }

    private function noDisponible(string $mensaje): JsonResponse
    {
        return response()->json([
            'exito' => false,
            'error' => 'SIAT_NO_DISPONIBLE',
            'mensaje' => $mensaje,
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
