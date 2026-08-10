<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\SiatException;
use App\Http\Controllers\Controller;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\RespuestaSiat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Alta local de puntos de venta y consulta de los que ya existen en el SIAT.
 *
 * Distincion importante: crear un punto de venta ACA no lo crea en el SIN. El
 * registro real lo hace el paso 10 del piloto, y es IRREVERSIBLE — el SIN
 * asigna un codigo nuevo en cada llamada y un punto de venta cerrado no se
 * reabre. Por eso existe la consulta: para ver que hay del otro lado antes de
 * registrar otro, y para importar el codigo real en vez de inventarlo.
 */
class PuntoVentaController extends Controller
{
    public function __construct(private readonly FabricaServicios $fabrica) {}

    public function store(Request $request, Sucursal $sucursal): RedirectResponse
    {
        $datos = $request->validate([
            'codigo_punto_venta' => ['required', 'integer', 'min:0'],
            'nombre' => ['required', 'string', 'max:255'],
            'tipo_punto_venta' => ['required', 'integer'],
        ]);

        $sucursal->puntosVenta()->create($datos + ['siguiente_factura' => 1, 'activo' => true]);

        return redirect()
            ->route('admin.empresas.show', $sucursal->empresa_id)
            ->with('estado', 'Punto de venta creado localmente. Todavia NO existe en el SIAT: eso lo hace el paso 10 del piloto.');
    }

    /**
     * Consulta al SIAT los puntos de venta ya registrados de una sucursal y los
     * deja en la sesion para mostrarlos en la ficha.
     */
    public function consultar(Sucursal $sucursal): RedirectResponse
    {
        $empresa = $sucursal->empresa;
        $cuis = $this->cuisDeLaSucursal($sucursal);

        if ($cuis === null) {
            return $this->volver($sucursal, 'Hace falta un CUIS vigente en algun punto de venta para consultar al SIAT.');
        }

        try {
            $respuesta = RespuestaSiat::desde(
                $this->fabrica->operaciones($empresa)
                    ->consultarPuntosVenta((int) $sucursal->codigo_sucursal, $cuis),
                'RespuestaConsultaPuntoVenta',
            );
        } catch (SiatException $e) {
            return $this->volver($sucursal, 'Error del SIAT: '.$e->getMessage());
        }

        return redirect()
            ->route('admin.empresas.show', $sucursal->empresa_id)
            ->with('puntos_venta_siat', [
                'sucursal_id' => $sucursal->id,
                'lista' => $this->normalizar($respuesta),
            ]);
    }

    /**
     * Adopta un codigo de punto de venta que ya existe en el SIAT.
     *
     * Sirve para reconciliar cuando el codigo local no coincide con el del SIN
     * (el local arranca en 0 y el SIN asigna el suyo), y para reutilizar uno ya
     * registrado en vez de crear otro duplicado.
     */
    public function adoptarCodigo(Request $request, PuntoVenta $puntoVenta): RedirectResponse
    {
        $datos = $request->validate([
            'codigo_punto_venta' => ['required', 'integer', 'min:0'],
        ]);

        $puntoVenta->update([
            'codigo_punto_venta' => $datos['codigo_punto_venta'],
            'registrado_en_siat' => now(),
        ]);

        return redirect()
            ->route('admin.empresas.show', $puntoVenta->sucursal->empresa_id)
            ->with('estado', "Punto de venta sincronizado con el codigo {$datos['codigo_punto_venta']} del SIAT.");
    }

    /**
     * Cualquier CUIS vigente de la sucursal sirve para consultar.
     */
    private function cuisDeLaSucursal(Sucursal $sucursal): ?string
    {
        foreach ($sucursal->puntosVenta as $puntoVenta) {
            $cuis = $puntoVenta->cuisVigente();

            if ($cuis !== null) {
                return $cuis->codigo;
            }
        }

        return null;
    }

    /**
     * La lista llega como objeto suelto cuando hay un solo punto de venta.
     *
     * @return list<array{codigo: int, nombre: string, tipo: string}>
     */
    private function normalizar(RespuestaSiat $respuesta): array
    {
        $lista = data_get($respuesta->crudo, 'listaPuntosVentas') ?? [];

        if (is_object($lista)) {
            $lista = [$lista];
        }

        return array_values(array_map(fn (mixed $pv): array => [
            'codigo' => (int) data_get($pv, 'codigoPuntoVenta'),
            'nombre' => (string) data_get($pv, 'nombrePuntoVenta'),
            'tipo' => (string) data_get($pv, 'tipoPuntoVenta'),
        ], (array) $lista));
    }

    private function volver(Sucursal $sucursal, string $mensaje): RedirectResponse
    {
        return redirect()
            ->route('admin.empresas.show', $sucursal->empresa_id)
            ->with('estado', $mensaje);
    }
}
