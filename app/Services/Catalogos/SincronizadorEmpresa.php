<?php

namespace App\Services\Catalogos;

use App\Exceptions\SiatException;
use App\Models\ActividadEconomica;
use App\Models\Empresa;
use App\Models\LeyendaFactura;
use App\Models\ProductoServicio;
use App\Models\PuntoVenta;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\RespuestaSiat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
    public function sincronizarTodo(PuntoVenta $puntoVenta): array
    {
        return [
            'actividades' => $this->sincronizarActividades($puntoVenta),
            'productos' => $this->sincronizarProductos($puntoVenta),
            'leyendas' => $this->sincronizarLeyendas($puntoVenta),
        ];
    }

    /**
     * Pide un catalogo del NIT con los tres datos tomados del MISMO punto de
     * venta: CUIS, sucursal y codigo.
     *
     * El SIN valida que el CUIS corresponda a esa sucursal y a ese punto de
     * venta. Antes se pasaba el CUIS suelto y la sucursal y el punto de venta
     * caian en su valor por defecto —0— asi que toda peticion viajaba con un
     * punto de venta que podia no existir: el SIN respondia 200 con
     * transaccion=false y "EL PUNTO DE VENTA ES INEXISTENTE O INVALIDO", y el
     * catalogo se "sincronizaba" con cero registros sin avisar.
     *
     * @throws SiatException si no hay CUIS vigente o si el SIN rechaza.
     */
    private function pedirCatalogo(PuntoVenta $puntoVenta, string $metodo): mixed
    {
        $cuis = $puntoVenta->cuisVigente();

        if ($cuis === null) {
            throw new SiatException(
                "El punto de venta {$puntoVenta->codigo_punto_venta} no tiene CUIS vigente: no se pueden pedir catalogos.",
            );
        }

        $respuesta = $this->fabrica->sincronizacion($puntoVenta->sucursal->empresa)->{$metodo}(
            $cuis->codigo,
            (int) $puntoVenta->sucursal->codigo_sucursal,
            (int) $puntoVenta->codigo_punto_venta,
        );

        $rechazo = RespuestaSiat::rechazoDeCatalogo($respuesta);

        if ($rechazo !== null) {
            throw new SiatException("El SIN rechazo '{$metodo}': {$rechazo}");
        }

        return $respuesta;
    }

    public function sincronizarActividades(PuntoVenta $puntoVenta): int
    {
        $empresa = $puntoVenta->sucursal->empresa;
        $respuesta = $this->pedirCatalogo($puntoVenta, 'listaActividades');
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

    public function sincronizarProductos(PuntoVenta $puntoVenta): int
    {
        $empresa = $puntoVenta->sucursal->empresa;
        $respuesta = $this->pedirCatalogo($puntoVenta, 'listaProductosServicios');
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

    public function sincronizarLeyendas(PuntoVenta $puntoVenta): int
    {
        $empresa = $puntoVenta->sucursal->empresa;
        $respuesta = $this->pedirCatalogo($puntoVenta, 'listaLeyendas');
        $lista = $this->extraerLista($respuesta, ['listaLeyendas']);

        // Una lista vacia no se toma como "el SIN ya no tiene leyendas": se toma
        // como que la respuesta no se pudo leer. extraerLista() devuelve [] sin
        // quejarse cuando el nodo cambia de nombre —ya paso con los tres
        // catalogos— y borrar contra eso dejaba a la empresa sin leyendas. La
        // leyenda es obligatoria en la cabecera: sin ella, el SIN rechaza TODA
        // factura siguiente. Se conserva lo que habia y se avisa.
        if ($lista === []) {
            Log::warning('El SIN no devolvio leyendas: se conservan las que ya estaban.', [
                'empresa_id' => $empresa->id,
            ]);

            return 0;
        }

        // El reemplazo va en una transaccion: las leyendas no tienen clave
        // estable, asi que hay que borrarlas para insertarlas, y sin
        // transaccion una falla a mitad de camino dejaba el catalogo cortado.
        return DB::transaction(function () use ($empresa, $lista): int {
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
        });
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
