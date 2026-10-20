<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\SiatException;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Services\Pruebas\EjecutorPruebas;
use App\Services\Siat\GestorCodigos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Alta y edicion de sucursales de una empresa.
 *
 * El sistema NO las crea en el SIAT: el SIN no expone ninguna operacion para
 * eso. Las registra el contribuyente en su Oficina Virtual y aca solo se
 * copian, con el MISMO codigo que les asigno el SIN. Un codigo inventado hace
 * que el SIN rechace toda factura de esa sucursal.
 */
class SucursalController extends Controller
{
    public function __construct(
        private readonly GestorCodigos $gestor,
        private readonly EjecutorPruebas $pruebas,
    ) {}

    public function store(Request $request, Empresa $empresa): RedirectResponse
    {
        $sucursal = $empresa->sucursales()->create($this->validar($request));

        return redirect()
            ->route('admin.empresas.show', $empresa)
            ->with('estado', 'Sucursal registrada. '.$this->prepararPuntoVentaPorDefecto($sucursal));
    }

    /**
     * Deja la sucursal con su punto de venta 0 y un CUIS NUEVO por cada
     * version de los servicios. Solo CUIS: el CUFD lo pide la emision o la
     * tarea horaria cuando haga falta.
     *
     * OJO con la palabra "crear": en el SIN NO se crea nada. El punto de venta
     * 0 ya existe alla desde que el contribuyente registro la sucursal en la
     * Oficina Virtual. Lo que se crea aca es la FILA LOCAL que hace falta para
     * guardar su CUIS, su CUFD y el correlativo de facturas.
     *
     * Por eso no se llama a registroPuntoVenta —que si crearia uno nuevo en el
     * SIN, con otro codigo, y es irreversible—: solo se le piden los codigos.
     *
     * En el piloto corre las pruebas de la etapa I de la sucursal
     * (EjecutorPruebas::pedirCuisDeEtapaUno): PV 1 y PV 0, V2 y V3, y quedan
     * contadas en la etapa. Si el SIN ya tenia un CUIS vigente, cierra
     * operaciones y lo pide de nuevo. Fuera del piloto, o si la sucursal no
     * tiene pruebas, va por GestorCodigos::pedirCuisNuevoEnTodasLasVersiones().
     *
     * Es BEST-EFFORT: si el SIAT no responde, la sucursal igual queda creada y
     * el operador reintenta con los botones de siempre. Una caida del SIN no
     * puede impedir dar de alta una sucursal.
     */
    private function prepararPuntoVentaPorDefecto(Sucursal $sucursal): string
    {
        $puntoVenta = $sucursal->puntosVenta()->create([
            'codigo_punto_venta' => PuntoVenta::CODIGO_IMPLICITO,
            'nombre' => $sucursal->nombre,
            'tipo_punto_venta' => 1,
            'siguiente_factura' => 1,
            'activo' => true,
        ]);

        $empresa = $sucursal->empresa;

        $resumen = $empresa->esSandbox()
            ? $this->pruebas->pedirCuisDeEtapaUno($empresa, (int) $sucursal->codigo_sucursal)
            : null;

        if ($resumen !== null) {
            // El detalle por prueba esta en la tarjeta "CUIS de la etapa I" de
            // la ficha; aca va el total y lo que fallo.
            $contadas = collect($resumen)->where('contada', true)->count();
            $fallas = collect($resumen)->where('contada', false)->pluck('texto')->implode(' ');

            return "CUIS de la etapa I: {$contadas} de ".count($resumen).' contados.'.($fallas !== '' ? " {$fallas}" : '');
        }

        try {
            $resumen = $this->gestor->pedirCuisNuevoEnTodasLasVersiones($puntoVenta);
        } catch (SiatException $e) {
            return "Se preparo su punto de venta 0, pero no se obtuvo su CUIS: {$e->getMessage()}";
        }

        return 'Su punto de venta 0 —el que ya tenia en el SIN— quedo configurado. '.implode(' ', $resumen);
    }

    /**
     * Corrige los datos de una sucursal ya creada.
     *
     * Existe porque municipio, direccion y telefono viajan en la cabecera de
     * CADA factura: sin edicion, un dato mal cargado quedaba fijo para siempre
     * y toda factura de esa sucursal salia con el campo en xsi:nil.
     *
     * El codigo_sucursal NO se edita: identifica a la sucursal ante el SIN y ya
     * esta escrito en las facturas emitidas y en sus CUF.
     */
    public function update(Request $request, Sucursal $sucursal): RedirectResponse
    {
        $sucursal->update($this->validar($request, conCodigo: false));

        return redirect()
            ->route('admin.empresas.show', $sucursal->empresa_id)
            ->with('estado', "Sucursal {$sucursal->codigo_sucursal} actualizada.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, bool $conCodigo = true): array
    {
        $reglas = [
            'nombre' => ['required', 'string', 'max:255'],
            // Los tres van en la cabecera de la factura. Se piden al crear para
            // que ninguna sucursal nazca incompleta: antes el formulario ni
            // siquiera mandaba direccion y telefono.
            'municipio' => ['required', 'string', 'max:255'],
            'direccion' => ['required', 'string', 'max:255'],
            'telefono' => ['required', 'string', 'max:50'],
        ];

        if ($conCodigo) {
            $reglas['codigo_sucursal'] = ['required', 'integer', 'min:0'];
        }

        return $request->validate($reglas);
    }
}
