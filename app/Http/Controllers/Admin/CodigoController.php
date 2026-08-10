<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\SiatException;
use App\Http\Controllers\Controller;
use App\Models\Cafc;
use App\Models\Cufd;
use App\Models\Cuis;
use App\Models\PuntoVenta;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\ServicioCodigos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Gestion de codigos CUIS / CUFD / CAFC de un punto de venta desde el panel.
 *
 * Cada codigo se puede obtener de dos formas:
 *   - Solicitandolo al SIAT (uso real; necesita WSDL vigente y token valido).
 *   - Cargandolo a mano (para probar el sistema sin conexion al SIN).
 *
 * En ambos casos el codigo se guarda como historial: nunca se sobreescribe el
 * anterior, solo se agrega el nuevo vigente (ver seccion 6.2).
 */
class CodigoController extends Controller
{
    /**
     * La fabrica se resuelve del contenedor en vez de construir el servicio a
     * mano: es lo que permite probar estas acciones sin el SIAT al otro lado.
     */
    public function __construct(private readonly FabricaServicios $fabrica) {}

    // --- Solicitud al SIAT --------------------------------------------------

    public function solicitarCuis(PuntoVenta $puntoVenta): RedirectResponse
    {
        return $this->solicitar($puntoVenta, function (ServicioCodigos $servicio) use ($puntoVenta) {
            $respuesta = $servicio->solicitarCuis($puntoVenta);

            Cuis::create([
                'punto_venta_id' => $puntoVenta->id,
                'codigo' => (string) data_get($respuesta, 'RespuestaCuis.codigo'),
                'fecha_vigencia' => now()->addYear(),
            ]);
        }, 'CUIS solicitado al SIAT.');
    }

    public function solicitarCufd(PuntoVenta $puntoVenta): RedirectResponse
    {
        return $this->solicitar($puntoVenta, function (ServicioCodigos $servicio) use ($puntoVenta) {
            $cuis = $puntoVenta->cuisVigente();

            if ($cuis === null) {
                throw new SiatException('No hay CUIS vigente: solicite el CUIS antes que el CUFD.');
            }

            $respuesta = $servicio->solicitarCufd($puntoVenta, $cuis->codigo);

            Cufd::create([
                'punto_venta_id' => $puntoVenta->id,
                'codigo' => (string) data_get($respuesta, 'RespuestaCufd.codigo'),
                'codigo_control' => (string) data_get($respuesta, 'RespuestaCufd.codigoControl'),
                'direccion' => (string) data_get($respuesta, 'RespuestaCufd.direccion'),
                'fecha_vigencia' => now()->addDay(),
            ]);
        }, 'CUFD solicitado al SIAT.');
    }

    public function solicitarCafc(PuntoVenta $puntoVenta): RedirectResponse
    {
        return $this->solicitar($puntoVenta, function (ServicioCodigos $servicio) use ($puntoVenta) {
            $cuis = $puntoVenta->cuisVigente();

            if ($cuis === null) {
                throw new SiatException('No hay CUIS vigente: solicite el CUIS antes que el CAFC.');
            }

            $respuesta = $servicio->solicitarCafc($puntoVenta, $cuis->codigo);

            Cafc::create([
                'punto_venta_id' => $puntoVenta->id,
                'codigo' => (string) data_get($respuesta, 'RespuestaCafc.codigo'),
                'cantidad_facturas' => (int) data_get($respuesta, 'RespuestaCafc.cantidadFacturas', 0),
                'fecha_vigencia' => now()->addMonths(2),
            ]);
        }, 'CAFC solicitado al SIAT.');
    }

    // --- Carga manual (para pruebas sin SOAP) -------------------------------

    public function cuisManual(Request $request, PuntoVenta $puntoVenta): RedirectResponse
    {
        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:100'],
            'vigencia_dias' => ['nullable', 'integer', 'min:1'],
        ]);

        Cuis::create([
            'punto_venta_id' => $puntoVenta->id,
            'codigo' => $datos['codigo'],
            'fecha_vigencia' => now()->addDays($datos['vigencia_dias'] ?? 365),
        ]);

        return $this->volver($puntoVenta, 'CUIS cargado manualmente.');
    }

    public function cufdManual(Request $request, PuntoVenta $puntoVenta): RedirectResponse
    {
        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:255'],
            // El codigo_control es la pieza que entra al calculo del CUF.
            'codigo_control' => ['required', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'vigencia_horas' => ['nullable', 'integer', 'min:1'],
        ]);

        Cufd::create([
            'punto_venta_id' => $puntoVenta->id,
            'codigo' => $datos['codigo'],
            'codigo_control' => $datos['codigo_control'],
            'direccion' => $datos['direccion'] ?? null,
            'fecha_vigencia' => now()->addHours($datos['vigencia_horas'] ?? 24),
        ]);

        return $this->volver($puntoVenta, 'CUFD cargado manualmente. Ya se puede emitir.');
    }

    public function cafcManual(Request $request, PuntoVenta $puntoVenta): RedirectResponse
    {
        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:255'],
            'cantidad_facturas' => ['required', 'integer', 'min:1'],
            'vigencia_dias' => ['nullable', 'integer', 'min:1'],
        ]);

        Cafc::create([
            'punto_venta_id' => $puntoVenta->id,
            'codigo' => $datos['codigo'],
            'cantidad_facturas' => $datos['cantidad_facturas'],
            'fecha_vigencia' => now()->addDays($datos['vigencia_dias'] ?? 30),
        ]);

        return $this->volver($puntoVenta, 'CAFC cargado manualmente.');
    }

    /**
     * Corre una solicitud al SIAT atrapando fallas para no romper el panel:
     * sin WSDL o token la operacion falla, pero el error se muestra como aviso.
     */
    private function solicitar(PuntoVenta $puntoVenta, callable $accion, string $exito): RedirectResponse
    {
        $empresa = $puntoVenta->sucursal->empresa;

        try {
            $accion($this->fabrica->codigos($empresa));
        } catch (SiatException $e) {
            return $this->volver($puntoVenta, 'Error del SIAT: '.$e->getMessage());
        }

        return $this->volver($puntoVenta, $exito);
    }

    private function volver(PuntoVenta $puntoVenta, string $mensaje): RedirectResponse
    {
        return redirect()
            ->route('admin.empresas.show', $puntoVenta->sucursal->empresa_id)
            ->with('estado', $mensaje);
    }
}
