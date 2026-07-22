<?php

namespace App\Services\Catalogos;

use App\Models\Catalogo;
use App\Models\Empresa;
use App\Services\Siat\ServicioSincronizacion;
use App\Services\Siat\SiatClient;
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

    public function __construct(private readonly ServicioSincronizacion $servicio) {}

    /**
     * Crea el sincronizador usando las credenciales de una empresa activa.
     */
    public static function paraEmpresa(Empresa $empresa): self
    {
        $servicio = new ServicioSincronizacion($empresa, new SiatClient($empresa));

        return new self($servicio);
    }

    /**
     * Sincroniza todas las parametricas globales. Devuelve cuantos registros
     * se guardaron por tipo.
     *
     * @return array<string, int>
     */
    public function sincronizarTodo(string $cuis): array
    {
        $resumen = [];

        foreach (self::PARAMETRICAS as $tipo => $operacion) {
            $respuesta = $this->servicio->parametrica($operacion, $cuis);
            $lista = $this->extraerLista($respuesta);
            $resumen[$tipo] = $this->guardar($tipo, $lista);
        }

        // Al terminar se invalida la cache para que la API sirva lo nuevo.
        Cache::forget('siat.catalogos');

        return $resumen;
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
