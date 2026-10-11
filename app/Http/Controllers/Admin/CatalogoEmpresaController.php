<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\SiatException;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Services\Catalogos\SincronizadorEmpresa;
use Illuminate\Http\RedirectResponse;

/**
 * Sincroniza a pedido los catalogos del NIT de una empresa: actividades,
 * productos homologados y leyendas.
 *
 * Corre dentro del request y no por cola porque son tres llamadas y el
 * operador necesita ver el resultado: es el paso que se hace al dar de alta
 * un cliente o despues de que homologa productos nuevos en la Oficina
 * Virtual. La sincronizacion diaria la hace la tarea programada.
 */
class CatalogoEmpresaController extends Controller
{
    public function __invoke(Empresa $empresa, SincronizadorEmpresa $sincronizador): RedirectResponse
    {
        $puntoVenta = $empresa->puntoVentaConCuisVigente();

        if ($puntoVenta === null) {
            return redirect()
                ->route('admin.empresas.show', $empresa)
                ->with('error', 'Ningun punto de venta tiene CUIS vigente: pedi un CUIS antes de sincronizar los catalogos.');
        }

        try {
            $resumen = $sincronizador->sincronizarTodo($puntoVenta);
        } catch (SiatException $e) {
            return redirect()
                ->route('admin.empresas.show', $empresa)
                ->with('error', "No se pudieron sincronizar los catalogos: {$e->getMessage()}");
        }

        return redirect()
            ->route('admin.empresas.show', $empresa)
            ->with('estado', "Catalogos sincronizados: {$resumen['actividades']} actividades, "
                ."{$resumen['productos']} productos y {$resumen['leyendas']} leyendas.");
    }
}
