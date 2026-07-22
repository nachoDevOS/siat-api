<?php

namespace App\Services\Catalogos;

use App\Models\ActividadEconomica;
use App\Models\Empresa;
use App\Models\LeyendaFactura;
use App\Models\ProductoServicio;
use App\Services\Siat\ServicioSincronizacion;
use App\Services\Siat\SiatClient;

/**
 * Sincroniza los catalogos POR EMPRESA: actividades economicas, productos
 * homologados y leyendas. Su contenido depende del NIT, asi que se corre con
 * las credenciales de cada cliente (al alta y semanalmente).
 *
 * OJO: la forma de la respuesta debe confirmarse contra el WSDL vigente (rule 7).
 */
class SincronizadorEmpresa
{
    public function __construct(
        private readonly Empresa $empresa,
        private readonly ServicioSincronizacion $servicio,
    ) {}

    public static function paraEmpresa(Empresa $empresa): self
    {
        return new self($empresa, new ServicioSincronizacion($empresa, new SiatClient($empresa)));
    }

    /**
     * Sincroniza los tres catalogos por empresa. Devuelve el conteo por tipo.
     *
     * @return array{actividades: int, productos: int, leyendas: int}
     */
    public function sincronizarTodo(string $cuis): array
    {
        return [
            'actividades' => $this->sincronizarActividades($cuis),
            'productos' => $this->sincronizarProductos($cuis),
            'leyendas' => $this->sincronizarLeyendas($cuis),
        ];
    }

    private function sincronizarActividades(string $cuis): int
    {
        $lista = $this->extraerLista($this->servicio->listaActividades($cuis), 'listaActividades');
        $total = 0;

        foreach ($lista as $item) {
            ActividadEconomica::updateOrCreate(
                [
                    'empresa_id' => $this->empresa->id,
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

    private function sincronizarProductos(string $cuis): int
    {
        $lista = $this->extraerLista($this->servicio->listaProductosServicios($cuis), 'listaProductos');
        $total = 0;

        foreach ($lista as $item) {
            ProductoServicio::updateOrCreate(
                [
                    'empresa_id' => $this->empresa->id,
                    'codigo_actividad' => (string) data_get($item, 'codigoActividad'),
                    'codigo_producto' => (string) data_get($item, 'codigoProducto'),
                ],
                ['descripcion' => (string) data_get($item, 'descripcionProducto')],
            );
            $total++;
        }

        return $total;
    }

    private function sincronizarLeyendas(string $cuis): int
    {
        $lista = $this->extraerLista($this->servicio->listaLeyendas($cuis), 'listaLeyendas');

        // Las leyendas se reemplazan enteras: son pocas y no tienen clave estable.
        LeyendaFactura::where('empresa_id', $this->empresa->id)->delete();
        $total = 0;

        foreach ($lista as $item) {
            LeyendaFactura::create([
                'empresa_id' => $this->empresa->id,
                'codigo_actividad' => (string) data_get($item, 'codigoActividad'),
                'descripcion_leyenda' => (string) data_get($item, 'descripcionLeyenda'),
            ]);
            $total++;
        }

        return $total;
    }

    /**
     * Normaliza la respuesta SOAP a un arreglo iterable, tolerando el caso de
     * un solo elemento (que SOAP entrega como objeto).
     *
     * @return array<int, mixed>
     */
    private function extraerLista(mixed $respuesta, string $clave): array
    {
        $lista = data_get($respuesta, "RespuestaListaParametricas.{$clave}")
            ?? data_get($respuesta, $clave)
            ?? [];

        if (is_object($lista)) {
            $lista = [$lista];
        }

        return (array) $lista;
    }
}
