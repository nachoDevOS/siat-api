<?php

namespace App\Services\Panel;

use App\Exceptions\SiatException;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\RespuestaSiat;
use Illuminate\Support\Facades\Cache;

/**
 * Trae del SIN los puntos de venta que tiene registrados una sucursal.
 *
 * La ficha del cliente los muestra al abrirse, porque lo que importa es lo que
 * existe del lado del SIN: un punto de venta que solo esta en nuestra base no
 * puede emitir, y uno que esta en el SIN y no en nuestra base es un codigo
 * disponible para adoptar.
 *
 * La respuesta se cachea: es una llamada SOAP que tarda entre 0,8 y 4 segundos
 * y la lista cambia solo cuando alguien registra un punto de venta, que es
 * excepcional. El boton "Consultar los del SIAT" fuerza el refresco.
 */
class PuntosVentaSiat
{
    /**
     * Minutos que se conserva la lista. Registrar un punto de venta es raro y
     * irreversible, asi que no hace falta preguntar seguido.
     */
    private const MINUTOS_CACHE = 30;

    public function __construct(private readonly FabricaServicios $fabrica) {}

    /**
     * Puntos de venta que el SIN tiene registrados en esta sucursal.
     *
     * Nunca lanza: si el SIAT no responde, la ficha tiene que seguir abriendose.
     * El motivo viaja en 'error' para poder mostrarlo.
     *
     * @return array{lista: list<array{codigo: int, nombre: string, tipo: string}>, error: ?string, consultado: bool}
     */
    public function deSucursal(Sucursal $sucursal): array
    {
        return Cache::remember(
            self::clave($sucursal),
            now()->addMinutes(self::MINUTOS_CACHE),
            fn (): array => $this->consultar($sucursal),
        );
    }

    /**
     * Descarta lo cacheado para que la proxima lectura vuelva a preguntar.
     */
    public function olvidar(Sucursal $sucursal): void
    {
        Cache::forget(self::clave($sucursal));
    }

    private static function clave(Sucursal $sucursal): string
    {
        return "panel.puntos_venta_siat.{$sucursal->id}";
    }

    /**
     * @return array{lista: list<array{codigo: int, nombre: string, tipo: string}>, error: ?string, consultado: bool}
     */
    private function consultar(Sucursal $sucursal): array
    {
        try {
            $cuis = $this->cuisParaConsultar($sucursal);

            $respuesta = RespuestaSiat::desde(
                $this->fabrica->operaciones($sucursal->empresa)->consultarPuntosVenta(
                    (int) $sucursal->codigo_sucursal,
                    $cuis,
                ),
                'RespuestaConsultaPuntoVenta',
            );
        } catch (SiatException $e) {
            // El SIAT caido no puede dejar la ficha inaccesible.
            return ['lista' => [], 'error' => $e->getMessage(), 'consultado' => false];
        }

        if (! $respuesta->aceptada) {
            return ['lista' => [], 'error' => $respuesta->motivo(), 'consultado' => false];
        }

        return [
            'lista' => $this->conImplicito($this->normalizar($respuesta)),
            'error' => null,
            'consultado' => true,
        ];
    }

    /**
     * Agrega el punto de venta 0 si el SIN no lo devolvio.
     *
     * consultaPuntoVenta NUNCA lo lista: solo informa los que se registraron por
     * API. Pero el 0 existe en toda sucursal —nace con ella en la Oficina
     * Virtual— y tiene su propio CUIS y CUFD. De hecho es el que se usa para
     * poder hacer esta misma consulta.
     *
     * Omitirlo daba una lista incompleta: el operador veia cinco puntos de venta
     * cuando en realidad tiene seis, y el unico con el que puede facturar desde
     * el minuto cero quedaba invisible.
     *
     * @param  list<array{codigo: int, nombre: string, tipo: string}>  $lista
     * @return list<array{codigo: int, nombre: string, tipo: string, implicito?: bool}>
     */
    private function conImplicito(array $lista): array
    {
        foreach ($lista as $pv) {
            // Si el SIN lo devolvio (no deberia), se respeta lo que dijo el SIN.
            if ($pv['codigo'] === PuntoVenta::CODIGO_IMPLICITO) {
                return $lista;
            }
        }

        array_unshift($lista, [
            'codigo' => PuntoVenta::CODIGO_IMPLICITO,
            'nombre' => 'Punto de venta por defecto',
            'tipo' => 'Implicito de la sucursal',
            'implicito' => true,
        ]);

        return $lista;
    }

    /**
     * CUIS con el que preguntarle al SIN por los puntos de venta de la sucursal.
     *
     * Se prefiere uno ya guardado. Si no hay ninguno, se pide uno al SIN para el
     * PUNTO DE VENTA 0, el implicito de toda sucursal.
     *
     * Sin esto habia un circulo cerrado: para ver que puntos de venta tiene el
     * contribuyente en el SIN hace falta un CUIS, y el panel solo sabia pedir
     * CUIS para un punto de venta local —que al dar de alta un cliente todavia
     * no existe—. El 0 no hay que crearlo ni registrarlo: ya esta del lado del
     * SIN, y el WSDL declara 'codigoPuntoVenta' como opcional en SolicitudCuis.
     *
     * @throws SiatException si el SIN no responde o no devuelve un CUIS.
     */
    public function cuisParaConsultar(Sucursal $sucursal): string
    {
        $guardado = $sucursal->puntosVenta
            ->map(fn (PuntoVenta $pv) => $pv->cuisVigente())
            ->filter()
            ->first();

        if ($guardado !== null) {
            return $guardado->codigo;
        }

        $respuesta = $this->fabrica->codigos($sucursal->empresa)
            ->solicitarCuisPara((int) $sucursal->codigo_sucursal);

        $codigo = (string) data_get($respuesta, 'RespuestaCuis.codigo');

        if (blank($codigo)) {
            // El SIN contesta 200 con el codigo vacio cuando rechaza.
            throw new SiatException(
                'El SIN no devolvio un CUIS para el punto de venta 0 de la sucursal '
                .$sucursal->codigo_sucursal.'. Verifica que la sucursal exista en la Oficina Virtual.',
            );
        }

        return $codigo;
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

        $normalizada = array_values(array_map(fn (mixed $pv): array => [
            'codigo' => (int) data_get($pv, 'codigoPuntoVenta'),
            'nombre' => (string) data_get($pv, 'nombrePuntoVenta'),
            'tipo' => (string) data_get($pv, 'tipoPuntoVenta'),
        ], (array) $lista));

        // El SIN los devuelve en orden de creacion; por codigo se leen mejor.
        usort($normalizada, fn (array $a, array $b): int => $a['codigo'] <=> $b['codigo']);

        return $normalizada;
    }
}
