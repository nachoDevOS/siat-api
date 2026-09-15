<?php

namespace App\Services\Catalogos;

use App\Exceptions\SiatException;
use App\Models\Catalogo;
use App\Models\PuntoVenta;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\RespuestaSiat;
use Illuminate\Support\Facades\Cache;

/**
 * Sincroniza los catalogos GLOBALES del SIN (parametricas: unidades de medida,
 * metodos de pago, motivos de anulacion, etc.). Son iguales para todos los
 * clientes, asi que basta una ejecucion usando las credenciales de cualquier
 * empresa activa.
 *
 * OJO: los nombres de operacion de cada parametrica y la forma de la respuesta
 * deben confirmarse contra el WSDL vigente (rule 7).
 */
class SincronizadorGlobal
{
    /**
     * Mapea el "tipo" con el que se guarda en la tabla catalogos contra la
     * operacion SOAP que lo trae. Ajustar nombres contra el WSDL.
     *
     * @var array<string, string>
     */
    private const PARAMETRICAS = [
        'unidades_medida' => 'sincronizarParametricaUnidadMedida',
        'tipos_moneda' => 'sincronizarParametricaTipoMoneda',
        'tipos_documento_identidad' => 'sincronizarParametricaTipoDocumentoIdentidad',
        'tipos_metodo_pago' => 'sincronizarParametricaTipoMetodoPago',
        'motivos_anulacion' => 'sincronizarParametricaMotivoAnulacion',
        'tipos_documento_sector' => 'sincronizarParametricaTipoDocumentoSector',
        'paises_origen' => 'sincronizarParametricaPaisOrigen',
        'tipos_punto_venta' => 'sincronizarParametricaTipoPuntoVenta',
    ];

    /**
     * La fabrica se resuelve del contenedor en vez de construir el servicio a
     * mano: es lo que permite sustituir la capa SOAP en las pruebas.
     */
    public function __construct(private readonly FabricaServicios $fabrica) {}

    /**
     * Sincroniza todas las parametricas globales con las credenciales del
     * contribuyente dueno de ese punto de venta.
     *
     * Recibe el PUNTO DE VENTA y no un CUIS suelto: el SIN valida que el CUIS
     * corresponda a la sucursal y al punto de venta que viajan en la misma
     * peticion. Antes se mandaba el CUIS por un lado y "punto de venta 0" por
     * otro, y el SIN rechazaba todo con "EL PUNTO DE VENTA ES INEXISTENTE O
     * INVALIDO". Tomando los tres datos del mismo punto de venta, no pueden
     * volver a desalinearse.
     *
     * @return array<string, int>
     *
     * @throws SiatException si el punto de venta no tiene CUIS vigente o si el
     *                       SIN rechaza la sincronizacion.
     */
    public function sincronizarTodo(PuntoVenta $puntoVenta): array
    {
        $cuis = $puntoVenta->cuisVigente();

        if ($cuis === null) {
            throw new SiatException(
                "El punto de venta {$puntoVenta->codigo_punto_venta} no tiene CUIS vigente: no se pueden pedir catalogos.",
            );
        }

        $servicio = $this->fabrica->sincronizacion($puntoVenta->sucursal->empresa);
        $resumen = [];

        foreach (self::PARAMETRICAS as $tipo => $operacion) {
            $respuesta = $servicio->parametrica(
                $operacion,
                $cuis->codigo,
                (int) $puntoVenta->sucursal->codigo_sucursal,
                (int) $puntoVenta->codigo_punto_venta,
            );

            // Un rechazo del SIN llega con HTTP 200 y lista vacia: sin mirarlo,
            // se guardaba "0 registros" y parecia que el catalogo estaba al dia.
            $rechazo = RespuestaSiat::rechazoDeCatalogo($respuesta);

            if ($rechazo !== null) {
                throw new SiatException("El SIN rechazo '{$operacion}': {$rechazo}");
            }

            $lista = $this->extraerLista($respuesta);
            $resumen[$tipo] = $this->guardar($tipo, $lista);
        }

        $this->olvidarCache();

        return $resumen;
    }

    /**
     * Invalida lo que la API tenga cacheado de cada tipo de catalogo.
     *
     * Antes se borraba la clave 'siat.catalogos', que no la escribe nadie: la
     * API cachea una clave por tipo ('siat.catalogos.unidades_medida', ...), asi
     * que el olvido no acertaba ninguna y seguia sirviendo datos viejos por una
     * hora despues de sincronizar.
     */
    private function olvidarCache(): void
    {
        foreach (array_keys(self::PARAMETRICAS) as $tipo) {
            Cache::forget("siat.catalogos.{$tipo}");
        }
    }

    /**
     * Guarda (upsert) una lista de codigos de un tipo. No borra lo anterior de
     * golpe: actualiza por codigo para no dejar la tabla vacia si el SIN falla.
     *
     * @param  list<array{codigo: string, descripcion: string}>  $lista
     */
    private function guardar(string $tipo, array $lista): int
    {
        foreach ($lista as $fila) {
            Catalogo::updateOrCreate(
                ['tipo' => $tipo, 'codigo_clasificador' => $fila['codigo']],
                ['descripcion' => $fila['descripcion']],
            );
        }

        return count($lista);
    }

    /**
     * Normaliza la respuesta SOAP a una lista simple de codigo + descripcion.
     *
     * La respuesta del SIN suele venir como respuesta->listaCodigos, cada uno
     * con ->codigoClasificador y ->descripcion. Verificar la forma exacta con
     * 'trace' contra el WSDL y ajustar aca si difiere.
     *
     * @return list<array{codigo: string, descripcion: string}>
     */
    private function extraerLista(mixed $respuesta): array
    {
        $lista = data_get($respuesta, 'RespuestaListaParametricas.listaCodigos')
            ?? data_get($respuesta, 'listaCodigos')
            ?? [];

        // Un solo elemento llega como objeto, no como arreglo: normalizamos.
        if (is_object($lista)) {
            $lista = [$lista];
        }

        $normalizada = [];

        foreach ((array) $lista as $item) {
            $normalizada[] = [
                'codigo' => (string) data_get($item, 'codigoClasificador'),
                'descripcion' => (string) data_get($item, 'descripcion'),
            ];
        }

        return $normalizada;
    }
}
