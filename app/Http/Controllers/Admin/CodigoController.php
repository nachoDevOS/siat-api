<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\SiatException;
use App\Http\Controllers\Controller;
use App\Models\PuntoVenta;
use App\Services\Siat\GestorCodigos;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Gestion de codigos CUIS / CUFD de un punto de venta desde el panel.
 *
 * Los codigos SIEMPRE se le piden al SIAT. Habia tambien una carga manual, para
 * poder probar sin conexion al SIN, y se quito: dejaba pegar cualquier cadena
 * sin que nada la validara, y un codigo_control equivocado entra al calculo del
 * CUF y hace rechazar TODAS las facturas del punto de venta. El riesgo no
 * compensaba la comodidad de probar sin red.
 *
 * El codigo se guarda como historial: nunca se sobreescribe el anterior, solo
 * se agrega el nuevo vigente (ver seccion 6.2).
 */
class CodigoController extends Controller
{
    /**
     * El gestor se resuelve del contenedor en vez de armar el servicio a mano:
     * es lo que permite probar estas acciones sin el SIAT al otro lado. Ademas
     * concentra la validacion de la respuesta, que antes estaba duplicada aca.
     */
    public function __construct(private readonly GestorCodigos $gestor) {}

    /**
     * Historial completo de CUIS y CUFD de un punto de venta.
     *
     * Vive en su propia pantalla y no dentro de la ficha del cliente porque el
     * SIN emite un CUFD por dia como minimo: en un ano son mas de 350 filas por
     * punto de venta, y ahi adentro no se podia ni mirar ni paginar.
     */
    public function historial(PuntoVenta $puntoVenta): View
    {
        $puntoVenta->load('sucursal.empresa');

        return view('admin.puntos-venta.codigos', [
            'puntoVenta' => $puntoVenta,
            'empresa' => $puntoVenta->sucursal->empresa,
            // Los que el sistema usa hoy: es contra estos que se compara todo
            // lo demas para decir "en uso" o "reemplazado".
            'cuisEnUso' => $puntoVenta->cuisVigente(),
            'cufdEnUso' => $puntoVenta->cufdVigente(),
            'listaCuis' => $puntoVenta->cuis()->withCount('cufds')->latest('id')->get(),
            // El CUFD es el que crece: se pagina.
            'listaCufd' => $puntoVenta->cufds()->with('cuis')->latest('id')->paginate(25),
        ]);
    }

    // --- Solicitud al SIAT --------------------------------------------------

    public function solicitarCuis(PuntoVenta $puntoVenta): RedirectResponse
    {
        return $this->solicitar(
            $puntoVenta,
            fn (GestorCodigos $gestor) => $gestor->solicitarCuis($puntoVenta),
            'CUIS solicitado al SIAT.',
        );
    }

    public function solicitarCufd(PuntoVenta $puntoVenta): RedirectResponse
    {
        return $this->solicitar(
            $puntoVenta,
            fn (GestorCodigos $gestor) => $gestor->solicitarCufd($puntoVenta),
            'CUFD solicitado al SIAT. Ya se puede emitir.',
        );
    }

    /**
     * Corre una solicitud al SIAT atrapando fallas para no romper el panel:
     * sin WSDL o token la operacion falla, pero el error se muestra como aviso.
     */
    private function solicitar(PuntoVenta $puntoVenta, callable $accion, string $exito): RedirectResponse
    {
        try {
            $accion($this->gestor);
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
