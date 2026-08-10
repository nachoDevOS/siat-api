<?php

namespace App\Services\Catalogos;

use App\Models\ActividadEconomica;
use App\Models\Empresa;
use App\Models\LeyendaFactura;
use App\Models\ProductoServicio;
use App\Services\Siat\FabricaServicios;

/**
 * Sincroniza los catalogos POR EMPRESA: actividades economicas, productos
 * homologados y leyendas. Su contenido depende del NIT, asi que se corre con
 * las credenciales de cada cliente (al alta y semanalmente).
 *
 * OJO: la forma de la respuesta debe confirmarse contra el WSDL vigente (rule 7).
 */
class SincronizadorEmpresa
{
    /**
     * La fabrica se resuelve del contenedor en vez de construir el servicio a
     * mano: es lo que permite sustituir la capa SOAP en las pruebas.
     */
    public function __construct(private readonly FabricaServicios $fabrica) {}

    /**
     * Sincroniza los tres catalogos por empresa. Devuelve el conteo por tipo.
     *
     * @return array{actividades: int, productos: int, leyendas: int}
     */
    public function sincronizarTodo(Empresa $empresa, string $cuis): array
    {
        return [
            'actividades' => $this->sincronizarActividades($empresa, $cuis),
            'productos' => $this->sincronizarProductos($empresa, $cuis),
            'leyendas' => $this->sincronizarLeyendas($empresa, $cuis),
        ];
    }

    public function sincronizarActividades(Empresa $empresa, string $cuis): int
    {
        $respuesta = $this->fabrica->sincronizacion($empresa)->listaActividades($cuis);
        $lista = $this->extraerLista($respuesta, ['listaActividades']);
        $total = 0;

        foreach ($lista as $item) {
            ActividadEconomica::updateOrCreate(
                [
                    'empresa_id' => $empresa->id,
                    'codigo_actividad' => (string) data_get($item, 'codigoCaeb'),
                ],
                [
                    'descripcion' => (string) data_get($item, 'descripcion'),
                    'tipo_actividad' => (string) data_get($item, 'tipoActividad'),
                ],
            );
            $total++;
        }

        return $total;
    }

    public function sincronizarProductos(Empresa $empresa, string $cuis): int
    {
        $respuesta = $this->fabrica->sincronizacion($empresa)->listaProductosServicios($cuis);
        $lista = $this->extraerLista($respuesta, ['listaCodigos', 'listaProductos']);
        $total = 0;

        foreach ($lista as $item) {
            ProductoServicio::updateOrCreate(
                [
                    'empresa_id' => $empresa->id,
                    'codigo_actividad' => (string) data_get($item, 'codigoActividad'),
                    'codigo_producto' => (string) data_get($item, 'codigoProducto'),
                ],
                ['descripcion' => (string) data_get($item, 'descripcionProducto')],
            );
            $total++;
        }

        return $total;
    }

    public function sincronizarLeyendas(Empresa $empresa, string $cuis): int
    {
        $respuesta = $this->fabrica->sincronizacion($empresa)->listaLeyendas($cuis);
        $lista = $this->extraerLista($respuesta, ['listaLeyendas']);

        // Las leyendas se reemplazan enteras: son pocas y no tienen clave estable.
        LeyendaFactura::where('empresa_id', $empresa->id)->delete();
        $total = 0;

        foreach ($lista as $item) {
            LeyendaFactura::create([
                'empresa_id' => $empresa->id,
                'codigo_actividad' => (string) data_get($item, 'codigoActividad'),
                'descripcion_leyenda' => (string) data_get($item, 'descripcionLeyenda'),
            ]);
            $total++;
        }

        return $total;
    }

    /**
     * Normaliza la respuesta SOAP a un arreglo iterable.
     *
     * VERIFICADO CONTRA EL WSDL (2026-08-10): el nodo raiz cambia en CADA
     * operacion, y el de la lista tampoco es uniforme:
     *
     *     sincronizarActividades            -> RespuestaListaActividades.listaActividades
     *     sincronizarListaProductosServicios-> RespuestaListaProductos.listaCodigos
     *     sincronizarListaLeyendasFactura   -> RespuestaListaParametricasLeyendas.listaLeyendas
     *
     * Antes se buscaba siempre bajo 'RespuestaListaParametricas', que es el de
     * las parametricas globales: ninguna de las tres coincidia y los tres
     * catalogos se sincronizaban con CERO registros sin dar error.
     *
     * En vez de codificar los tres nombres, se descarta el envoltorio (siempre
     * es una sola propiedad) y se busca la lista adentro. Asi un renombre del
     * nodo raiz no vuelve a romper esto en silencio.
     *
     * @param  list<string>  $claves  nombres posibles del nodo de la lista.
     * @return array<int, mixed>
     */
    private function extraerLista(mixed $respuesta, array $claves): array
    {
        $cuerpo = $respuesta;

        // El envoltorio 'RespuestaListaX' es siempre una sola propiedad: se baja
        // un nivel sin depender de como se llame.
        $propiedades = is_object($respuesta) ? get_object_vars($respuesta) : (array) $respuesta;

        if (count($propiedades) === 1) {
            $cuerpo = reset($propiedades);
        }

        $lista = null;

        foreach ($claves as $clave) {
            $lista = data_get($cuerpo, $clave) ?? data_get($respuesta, $clave);

            if ($lista !== null) {
                break;
            }
        }

        if ($lista === null) {
            return [];
        }

        // Un solo elemento llega como objeto, no como arreglo.
        if (is_object($lista)) {
            $lista = [$lista];
        }

        return (array) $lista;
    }
}
