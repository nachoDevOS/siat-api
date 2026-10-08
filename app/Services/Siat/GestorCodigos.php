<?php

namespace App\Services\Siat;

use App\Exceptions\SiatException;
use App\Models\Cufd;
use App\Models\Cuis;
use App\Models\PuntoVenta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Pide un CUIS o un CUFD al SIN y lo guarda.
 *
 * Existe para que haya UN solo lugar que lo haga. Estaba copiado en el panel,
 * en el job horario y en el ejecutor del piloto, y las copias no eran iguales:
 * solo la del piloto comprobaba que el SIN hubiera devuelto algo. Las otras dos
 * guardaban codigos vacios ante un rechazo —el SIN contesta HTTP 200 con el
 * campo en blanco— y un CUFD sin codigo_control envenena el CUF de todas las
 * facturas del punto de venta.
 */
class GestorCodigos
{
    public function __construct(private readonly FabricaServicios $fabrica) {}

    /**
     * Solicita el CUIS del punto de venta y lo guarda como historial.
     *
     * @throws SiatException si el SIN no devuelve un codigo.
     */
    public function solicitarCuis(PuntoVenta $puntoVenta): Cuis
    {
        $respuesta = $this->fabrica
            ->codigos($puntoVenta->sucursal->empresa)
            ->solicitarCuis($puntoVenta);

        return $this->guardarCuis($puntoVenta, $respuesta);
    }

    /**
     * Guarda el CUIS de una respuesta del SIN ya obtenida.
     *
     * Separado de solicitarCuis() para el piloto: ahi hace falta mirar la
     * respuesta cruda —si 'transaccion' vino en false, el SIN devolvio el CUIS
     * que ya existia y NO cuenta la prueba— sin dejar de guardar el codigo.
     *
     * @throws SiatException si la respuesta no trae codigo.
     */
    public function guardarCuis(PuntoVenta $puntoVenta, mixed $respuesta): Cuis
    {
        $codigo = (string) data_get($respuesta, 'RespuestaCuis.codigo');

        if (blank($codigo)) {
            throw new SiatException(
                "El SIAT no devolvio un codigo CUIS para el punto de venta {$puntoVenta->codigo_punto_venta}. No se guardo nada.",
            );
        }

        // El SIN responde transaccion=false cuando ya habia un CUIS vigente
        // (codigo 980) pero IGUAL devuelve el codigo bueno. No es un error: es
        // el CUIS que corresponde usar. Se deja constancia y se sigue.
        $this->registrarAviso($respuesta, 'RespuestaCuis', $puntoVenta);

        // El CUIS dura cerca de un anio, asi que pedirlo dos veces devuelve el
        // MISMO codigo. Con create() cada clic dejaba una fila identica mas y el
        // historial se llenaba de duplicados que no existen del lado del SIN.
        // La identidad del codigo es el codigo: si ya esta, se refresca.
        return Cuis::updateOrCreate(
            ['punto_venta_id' => $puntoVenta->id, 'codigo' => $codigo],
            ['fecha_vigencia' => $this->vigencia($respuesta, 'RespuestaCuis', now()->addYear())],
        );
    }

    /**
     * Solicita el CUFD del punto de venta y lo guarda. Necesita CUIS vigente.
     *
     * @throws SiatException si no hay CUIS vigente o si el SIN no devuelve
     *                       codigo y codigo de control.
     */
    public function solicitarCufd(PuntoVenta $puntoVenta): Cufd
    {
        $cuis = $puntoVenta->cuisVigente();

        if ($cuis === null) {
            throw new SiatException(
                "El punto de venta {$puntoVenta->codigo_punto_venta} no tiene CUIS vigente: solicitalo antes que el CUFD.",
            );
        }

        $respuesta = $this->fabrica
            ->codigos($puntoVenta->sucursal->empresa)
            ->solicitarCufd($puntoVenta, $cuis->codigo);

        return $this->guardarCufd($puntoVenta, $cuis, $respuesta);
    }

    /**
     * Guarda el CUFD de una respuesta del SIN ya obtenida.
     *
     * Separado de solicitarCufd() por lo mismo que guardarCuis(): el piloto
     * necesita mirar 'transaccion' en la respuesta cruda sin dejar de guardar
     * el codigo, que es el que hay que usar para facturar.
     *
     * @param  Cuis  $cuis  CUIS con el que se pidio: queda atado al CUFD.
     *
     * @throws SiatException si faltan codigo o codigo de control.
     */
    public function guardarCufd(PuntoVenta $puntoVenta, Cuis $cuis, mixed $respuesta): Cufd
    {
        $codigo = (string) data_get($respuesta, 'RespuestaCufd.codigo');
        $codigoControl = (string) data_get($respuesta, 'RespuestaCufd.codigoControl');

        // El codigo_control entra al calculo del CUF. Uno vacio no da error en
        // ningun lado: produce CUF invalidos para TODAS las facturas de este
        // punto de venta hasta que alguien lo note. Y como cufdVigente() toma el
        // ultimo por id, el CUFD vacio le gana al bueno anterior.
        if (blank($codigo) || blank($codigoControl)) {
            throw new SiatException(
                "El SIAT no devolvio codigo y codigo de control del CUFD para el punto de venta {$puntoVenta->codigo_punto_venta}. No se guardo nada.",
            );
        }

        // A diferencia del CUIS, el SIN emite un CUFD NUEVO en cada solicitud,
        // aunque el anterior siga dentro de sus 24 h: se comprobo pidiendo dos
        // seguidos y devolvio codigos distintos. Asi que cada uno es una fila
        // propia y el anterior queda reemplazado, no duplicado. El
        // updateOrCreate queda igual por si alguna vez repite codigo.
        return Cufd::updateOrCreate(
            ['punto_venta_id' => $puntoVenta->id, 'codigo' => $codigo],
            [
                // Queda atado al CUIS con el que el SIN lo emitio: es lo que
                // permite reconstruir de que CUIS salio cada CUF.
                'cuis_id' => $cuis->id,
                'codigo_control' => $codigoControl,
                'direccion' => (string) data_get($respuesta, 'RespuestaCufd.direccion'),
                // El CUFD dura 24 horas desde su emision.
                'fecha_vigencia' => $this->vigencia($respuesta, 'RespuestaCufd', now()->addDay()),
            ],
        );
    }

    /**
     * Fecha de vigencia que declara el SIN, no la que calculamos nosotros.
     *
     * Es la diferencia entre creer y saber. Cuando el SIN devuelve un CUIS que
     * YA existia —lo hace con el codigo 980— su vigencia arranca del dia en que
     * lo emitio, no de hoy: calcular "ahora + 1 anio" lo daba por valido 36 dias
     * de mas en un caso real. Con un CUIS vencido el SIN rechaza todo, y el
     * panel habria mostrado el semaforo en verde.
     *
     * El respaldo local queda por si la respuesta no trae el campo.
     */
    private function vigencia(mixed $respuesta, string $raiz, Carbon $respaldo): Carbon
    {
        $declarada = data_get($respuesta, "{$raiz}.fechaVigencia");

        if (blank($declarada)) {
            return $respaldo;
        }

        try {
            return Carbon::parse((string) $declarada);
        } catch (\Throwable) {
            // Un formato inesperado no puede tumbar la solicitud entera.
            return $respaldo;
        }
    }

    /**
     * Deja en el log los mensajes que el SIN adjunta a una respuesta aceptada.
     *
     * No son errores —el codigo vino— pero explican que paso: "EXISTE UN CUIS
     * VIGENTE" es la diferencia entre haber emitido uno nuevo y haber recibido
     * el de antes.
     */
    private function registrarAviso(mixed $respuesta, string $raiz, PuntoVenta $puntoVenta): void
    {
        $descripcion = (string) data_get($respuesta, "{$raiz}.mensajesList.descripcion");

        if (blank($descripcion)) {
            return;
        }

        Log::info('El SIN adjunto un mensaje al entregar el codigo.', [
            'punto_venta_id' => $puntoVenta->id,
            'codigo' => data_get($respuesta, "{$raiz}.mensajesList.codigo"),
            'mensaje' => $descripcion,
        ]);
    }
}
