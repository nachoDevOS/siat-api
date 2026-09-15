<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\SiatException;
use App\Http\Controllers\Controller;
use App\Models\Cufd;
use App\Models\Cuis;
use App\Models\PuntoVenta;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\ServicioCodigos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Gestion de codigos CUIS / CUFD de un punto de venta desde el panel.
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

            $codigo = (string) data_get($respuesta, 'RespuestaCuis.codigo');

            // El SIN puede rechazar sin SoapFault: responde 200 y el codigo
            // viene vacio. Guardarlo igual dejaba un CUIS '' marcado vigente
            // un ano, y el siguiente pedido de CUFD lo tomaba como valido.
            if (blank($codigo)) {
                throw new SiatException('El SIAT no devolvio un codigo CUIS. No se guardo nada.');
            }

            Cuis::create([
                'punto_venta_id' => $puntoVenta->id,
                'codigo' => $codigo,
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

            $codigo = (string) data_get($respuesta, 'RespuestaCufd.codigo');
            $codigoControl = (string) data_get($respuesta, 'RespuestaCufd.codigoControl');

            // El codigo_control entra al calculo del CUF. Uno vacio no da error
            // en ningun lado: produce CUF invalidos para TODAS las facturas de
            // este punto de venta hasta que alguien lo note. Y como cufdVigente()
            // toma el ultimo por id, el CUFD vacio le gana al bueno anterior.
            if (blank($codigo) || blank($codigoControl)) {
                throw new SiatException(
                    'El SIAT no devolvio codigo y codigo de control del CUFD. No se guardo nada.',
                );
            }

            Cufd::create([
                'punto_venta_id' => $puntoVenta->id,
                'codigo' => $codigo,
                'codigo_control' => $codigoControl,
                'direccion' => (string) data_get($respuesta, 'RespuestaCufd.direccion'),
                'fecha_vigencia' => now()->addDay(),
            ]);
        }, 'CUFD solicitado al SIAT.');
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
