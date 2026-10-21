<?php

namespace App\Services\Siat;

use App\Exceptions\SiatException;
use App\Models\Cufd;
use App\Models\Cuis;
use App\Models\Empresa;
use App\Models\PuntoVenta;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

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
    /**
     * Codigo con el que el SIN avisa que ya habia un CUIS vigente: devuelve
     * ese mismo en vez de emitir uno.
     */
    private const CUIS_YA_VIGENTE = 980;

    /**
     * Pedidos de CUIS despues de cerrar operaciones, y segundos de pausa antes
     * de cada uno. Respaldo para cuando el cierre no puede ir por /v2 (ver
     * versionParaCerrar) o el SIN tarda igual.
     */
    public const INTENTOS_TRAS_CIERRE = 3;

    public const PAUSA_TRAS_CIERRE = 2;

    /**
     * Segundos minimos entre que el SIN emite un CUIS y se pide el siguiente
     * en el mismo punto de venta.
     *
     * El SIN arma el codigo con la hora: pedido muy pronto, genera el MISMO y
     * su base lo rechaza ("duplicate key value violates unique constraint
     * isfe_cuiscon_idx", visto por /v2 el 20/10/2026). Por /sfd ese error no
     * vuelve: el pedido se cuelga hasta el timeout. Medido: a los 20-25 s
     * falla, a los 40 s sale. Era la causa del "Sin respuesta" y del 966.
     */
    public const SEGUNDOS_ENTRE_EMISIONES = 60;

    /**
     * Espera lo que falte para que el CUIS que se va a cerrar cumpla
     * SEGUNDOS_ENTRE_EMISIONES desde su emision. La hora de emision sale de la
     * vigencia que dio el SIN (emision + 1 ano), no de cuando se guardo aca:
     * un CUIS viejo que vuelve con 980 se guarda recien, pero no hace esperar.
     */
    public function esperarVentanaDeEmision(PuntoVenta $puntoVenta, string $codigo): void
    {
        $cuis = $puntoVenta->cuis()->where('codigo', $codigo)->whereNull('cerrado_en')->latest('id')->first();

        if ($cuis === null) {
            return;
        }

        $transcurridos = $cuis->fecha_vigencia->copy()->subYear()->diffInSeconds(now());
        $faltan = (int) ceil(self::SEGUNDOS_ENTRE_EMISIONES - $transcurridos);

        if ($faltan > 0) {
            Sleep::for($faltan)->seconds();
        }
    }

    /**
     * Version por la que se cierran las operaciones: V2 si el ambiente la
     * tiene (el piloto), aunque el CUIS nuevo se pida por V3.
     *
     * Medido en el piloto el 20/10/2026: tras cerrar por /sfd, el primer
     * pedido de CUIS por /sfd nunca responde (con 30 s de espera da timeout,
     * con 90 s el propio SIN corta con "Gateway Time-out" a los 62 s, y una
     * pausa de 20 s no cambia nada). Cerrando por /v2 el pedido por /sfd sale
     * al primer intento. El CUIS es compartido entre versiones, asi que el
     * cierre por /v2 lo cierra tambien para /sfd.
     */
    public static function versionParaCerrar(Empresa $empresa): string
    {
        return filled(config("siat.urls_v2.{$empresa->codigo_ambiente}"))
            ? SiatClient::VERSION_V2
            : SiatClient::VERSION_V3;
    }

    public function __construct(private readonly FabricaServicios $fabrica) {}

    /**
     * Deja el punto de venta con un CUIS NUEVO pedido por cada version de los
     * servicios que tenga el ambiente: V2 (/v2) si esta configurada y despues
     * V3 (/sfd). La V3 va al final porque el SIN comparte el CUIS entre
     * versiones y el ultimo emitido es el que queda vigente: el sistema
     * factura con la V3. Solo CUIS: el CUFD lo pide la emision o la tarea
     * horaria cuando haga falta.
     *
     * Si el SIN ya tenia un CUIS vigente (980, por ejemplo de un alta
     * anterior), cierra las operaciones del sistema en ese punto de venta y
     * lo vuelve a pedir: es la unica forma de que emita uno nuevo. Comprobado
     * en el piloto (etapa I, 19/10/2026) en las dos versiones.
     *
     * @return list<string> una linea por version con lo que se obtuvo.
     *
     * @throws SiatException en la primera version que falle; las anteriores
     *                       quedan guardadas.
     */
    public function pedirCuisNuevoEnTodasLasVersiones(PuntoVenta $puntoVenta): array
    {
        $empresa = $puntoVenta->sucursal->empresa;
        $versiones = filled(config("siat.urls_v2.{$empresa->codigo_ambiente}"))
            ? [SiatClient::VERSION_V2, SiatClient::VERSION_V3]
            : [SiatClient::VERSION_V3];

        $resumen = [];

        foreach ($versiones as $version) {
            $etiqueta = strtoupper($version);

            try {
                $cuis = $this->cuisNuevoPorVersion($puntoVenta, $version);
            } catch (SiatException $e) {
                throw new SiatException(implode(' ', [...$resumen, "{$etiqueta}: {$e->getMessage()}"]), $e);
            }

            $resumen[] = "{$etiqueta}: CUIS nuevo {$cuis->codigo}.";
        }

        return $resumen;
    }

    /**
     * Pide el CUIS por una version y, si el SIN devuelve el vigente (980),
     * cierra las operaciones con ese codigo y lo pide otra vez.
     *
     * @throws SiatException si no cierra, no entrega CUIS o repite el mismo.
     */
    private function cuisNuevoPorVersion(PuntoVenta $puntoVenta, string $version): Cuis
    {
        $empresa = $puntoVenta->sucursal->empresa;
        $codigos = $this->fabrica->codigos($empresa, $version);

        $respuesta = $codigos->solicitarCuis($puntoVenta);
        $leida = RespuestaSiat::desde($respuesta, 'RespuestaCuis');
        $yaVigente = collect($leida->mensajes)->contains(fn (array $m): bool => (int) $m['codigo'] === self::CUIS_YA_VIGENTE);

        if ($yaVigente) {
            $anterior = (string) data_get($respuesta, 'RespuestaCuis.codigo');
            // Lo guarda (con la vigencia del SIN) para saber cuando se emitio.
            $this->guardarCuis($puntoVenta, $respuesta, $version);
            $this->esperarVentanaDeEmision($puntoVenta, $anterior);

            $cierre = RespuestaSiat::desde(
                $this->fabrica->operaciones($empresa, self::versionParaCerrar($empresa))->cerrarOperacionesSistema(
                    (int) $puntoVenta->sucursal->codigo_sucursal,
                    (int) $puntoVenta->codigo_punto_venta,
                    $anterior,
                ),
                'RespuestaCierreSistemas',
            );

            if (! $cierre->aceptada) {
                throw new SiatException("el SIN no cerro las operaciones del CUIS {$anterior}: {$cierre->motivo()}");
            }

            // Cerrado del lado del SIN ya no sirve tampoco en la base.
            $puntoVenta->cuis()->where('codigo', $anterior)->get()->each->marcarCerrado();

            // Tras el cierre el punto de venta queda SIN CUIS hasta que llegue el
            // nuevo: un timeout aca lo dejaba asi. Ver INTENTOS_TRAS_CIERRE.
            $respuesta = retry(
                self::INTENTOS_TRAS_CIERRE,
                function () use ($codigos, $puntoVenta) {
                    Sleep::for(self::PAUSA_TRAS_CIERRE)->seconds();

                    return $codigos->solicitarCuis($puntoVenta);
                },
                when: fn (\Throwable $e): bool => $e instanceof SiatException,
            );

            if ((string) data_get($respuesta, 'RespuestaCuis.codigo') === $anterior) {
                throw new SiatException("se cerraron las operaciones, pero el SIN devolvio el mismo CUIS ({$anterior}).");
            }
        }

        return $this->guardarCuis($puntoVenta, $respuesta, $version);
    }

    /**
     * CUIS vigente del punto de venta; si no hay, lo pide al SIN.
     *
     * Los sistemas de venta no manejan codigos: esta API los consigue sola
     * cuando hacen falta. Pedirlo con uno vigente seria inutil (el SIN
     * devuelve el mismo con el codigo 980), por eso solo se pide si no hay.
     *
     * @throws SiatException si el SIN no lo entrega.
     */
    public function asegurarCuis(PuntoVenta $puntoVenta): Cuis
    {
        return $puntoVenta->cuisVigente() ?? $this->solicitarCuis($puntoVenta);
    }

    /**
     * CUFD vigente del punto de venta; si no hay, lo pide al SIN (y antes el
     * CUIS, si tambien falta).
     *
     * Va con un lock por punto de venta: si dos cajas venden a la vez justo
     * cuando vencio el CUFD, la segunda espera a la primera y usa el CUFD que
     * esta consiguio, en vez de pedir otro.
     *
     * @throws SiatException si el SIN no responde o no entrega codigos.
     * @throws LockTimeoutException si otra peticion retiene el lock demasiado.
     */
    public function asegurarCufd(PuntoVenta $puntoVenta): Cufd
    {
        $vigente = $puntoVenta->cufdVigente();

        if ($vigente !== null) {
            return $vigente;
        }

        return Cache::lock("siat:cufd:{$puntoVenta->id}", 60)->block(
            (int) config('siat.timeout') + 5,
            // Dentro del lock se vuelve a mirar: la peticion que lo tenia
            // pudo haber conseguido el CUFD mientras esta esperaba.
            fn (): Cufd => $puntoVenta->cufdVigente() ?? $this->renovarCufd($puntoVenta),
        );
    }

    /**
     * Pide SIEMPRE un CUFD nuevo, consiguiendo antes el CUIS si no hay uno
     * vigente. Es lo que usan la renovacion preventiva y la de emergencia.
     *
     * @throws SiatException
     */
    public function renovarCufd(PuntoVenta $puntoVenta): Cufd
    {
        $this->asegurarCuis($puntoVenta);

        return $this->solicitarCufd($puntoVenta);
    }

    /**
     * Pide el CUIS y, si el SIN entrego uno NUEVO, tambien un CUFD atado a el.
     *
     * El SIN ata cada CUFD al CUIS con el que se pidio: verificado en el
     * piloto, un CUFD viejo con el CUIS nuevo responde [913] CUIS INVALIDO. Si
     * el CUIS cambia y el CUFD no, en cuanto el CUIS anterior deja de valer las
     * facturas salen observadas. Si el SIN devolvio el MISMO CUIS (codigo 980,
     * "ya habia uno vigente") no cambio nada y el CUFD actual sigue sirviendo.
     *
     * Es lo que usan el boton "Solicitar CUIS" del panel y la renovacion
     * diaria; el alta de sucursal o punto de venta ya pide los dos.
     *
     * @throws SiatException si el SIN no entrega el CUIS, o si entrego uno
     *                       nuevo pero no el CUFD (el CUIS queda guardado).
     */
    public function renovarCuis(PuntoVenta $puntoVenta): Cuis
    {
        $cuis = $this->solicitarCuis($puntoVenta);

        if (! $cuis->wasRecentlyCreated) {
            return $cuis;
        }

        try {
            $this->solicitarCufd($puntoVenta);
        } catch (SiatException $e) {
            throw new SiatException("CUIS nuevo obtenido, pero el CUFD atado a el no: {$e->getMessage()}", $e);
        }

        return $cuis;
    }

    /**
     * Obliga al SIN a entregar un CUIS NUEVO: cierra las operaciones del
     * sistema en el punto de venta y vuelve a pedirlo (con su CUFD).
     *
     * Es la unica forma: mientras el CUIS siga vigente, pedirlo devuelve el
     * mismo (980). Antes de cerrar se le pregunta al SIN cual es su CUIS
     * vigente en vez de confiar en la base local, porque el cierre se hace
     * sobre ese.
     *
     * Ojo: las facturas que todavia no viajaron con el CUFD anterior pueden
     * volver con 913. El panel lo advierte antes de llamar.
     *
     * No se permite en piloto: el 19/10/2026 el piloto acepto el cierre y al
     * pedir el CUIS nuevo volvio a generar el MISMO codigo, su base lo rechazo
     * por clave duplicada y desde ahi respondio 966 a todo pedido de CUIS en
     * ese punto de venta. Las pruebas no lo necesitan.
     *
     * @throws SiatException si la empresa esta en piloto, si el SIN no cierra
     *                       las operaciones, si despues no entrega CUIS o
     *                       devuelve el mismo, o si falla el CUFD nuevo.
     */
    public function forzarCuisNuevo(PuntoVenta $puntoVenta): Cuis
    {
        if ($puntoVenta->sucursal->empresa->esSandbox()) {
            throw new SiatException('Forzar un CUIS nuevo no esta permitido en el ambiente piloto: el SIN puede dejar el punto de venta sin CUIS. Usa "Pedir CUIS".');
        }

        $actual = $this->solicitarCuis($puntoVenta);

        $cierre = RespuestaSiat::desde(
            $this->fabrica->operaciones($puntoVenta->sucursal->empresa, self::versionParaCerrar($puntoVenta->sucursal->empresa))->cerrarOperacionesSistema(
                (int) $puntoVenta->sucursal->codigo_sucursal,
                (int) $puntoVenta->codigo_punto_venta,
                $actual->codigo,
            ),
            'RespuestaCierreSistemas',
        );

        if (! $cierre->aceptada) {
            throw new SiatException("El SIN no cerro las operaciones, el CUIS sigue siendo {$actual->codigo}: {$cierre->motivo()}");
        }

        // Cerrado del lado del SIN, el CUIS ya no sirve: si queda vigente en la
        // base, asegurarCuis() lo seguiria usando y todo volveria con 913.
        $actual->marcarCerrado();

        try {
            $nuevo = $this->renovarCuis($puntoVenta);
        } catch (SiatException $e) {
            throw new SiatException("Se cerraron las operaciones con el CUIS {$actual->codigo}, pero el SIN no entrego el nuevo: {$e->getMessage()} No vuelvas a forzar: reintenta \"Pedir CUIS\" mas tarde.", $e);
        }

        if ($nuevo->codigo === $actual->codigo) {
            throw new SiatException("Se cerraron las operaciones, pero el SIN devolvio el mismo CUIS ({$actual->codigo}). Reintenta en unos minutos.");
        }

        return $nuevo;
    }

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
    public function guardarCuis(PuntoVenta $puntoVenta, mixed $respuesta, string $version = SiatClient::VERSION_V3): Cuis
    {
        $codigo = (string) data_get($respuesta, 'RespuestaCuis.codigo');

        // El motivo del SIN va en el mensaje: sin el, un 966 ("no se puede
        // recuperar los datos del contribuyente") se veia igual que cualquier
        // otro rechazo y habia que ir a buscarlo a logs_siat.
        if (blank($codigo)) {
            throw new SiatException(
                "El SIAT no devolvio un codigo CUIS para el punto de venta {$puntoVenta->codigo_punto_venta}. No se guardo nada. "
                .RespuestaSiat::desde($respuesta, 'RespuestaCuis')->motivo(),
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
        $cuis = Cuis::firstOrNew(['punto_venta_id' => $puntoVenta->id, 'codigo' => $codigo]);
        $cuis->fecha_vigencia = $this->vigencia($respuesta, 'RespuestaCuis', now()->addYear());
        // Ruta por la que se pidio (/v2 o /sfd). El SIN comparte el codigo
        // entre versiones: si otra version lo devuelve con el 980, sigue siendo
        // el que emitio la primera, asi que la version no se pisa.
        $cuis->version ??= $version;
        $cuis->save();

        return $cuis;
    }

    /**
     * Solicita el CUFD del punto de venta y lo guarda. Necesita CUIS vigente.
     *
     * @throws SiatException si no hay CUIS vigente o si el SIN no devuelve
     *                       codigo y codigo de control.
     */
    public function solicitarCufd(PuntoVenta $puntoVenta, string $version = SiatClient::VERSION_V3): Cufd
    {
        $cuis = $puntoVenta->cuisVigente();

        if ($cuis === null) {
            throw new SiatException(
                "El punto de venta {$puntoVenta->codigo_punto_venta} no tiene CUIS vigente: solicitalo antes que el CUFD.",
            );
        }

        $respuesta = $this->fabrica
            ->codigos($puntoVenta->sucursal->empresa, $version)
            ->solicitarCufd($puntoVenta, $cuis->codigo);

        return $this->guardarCufd($puntoVenta, $cuis, $respuesta, $version);
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
    public function guardarCufd(PuntoVenta $puntoVenta, Cuis $cuis, mixed $respuesta, string $version = SiatClient::VERSION_V3): Cufd
    {
        $codigo = (string) data_get($respuesta, 'RespuestaCufd.codigo');
        $codigoControl = (string) data_get($respuesta, 'RespuestaCufd.codigoControl');

        // El codigo_control entra al calculo del CUF. Uno vacio no da error en
        // ningun lado: produce CUF invalidos para TODAS las facturas de este
        // punto de venta hasta que alguien lo note. Y como cufdVigente() toma el
        // ultimo por id, el CUFD vacio le gana al bueno anterior.
        if (blank($codigo) || blank($codigoControl)) {
            throw new SiatException(
                "El SIAT no devolvio codigo y codigo de control del CUFD para el punto de venta {$puntoVenta->codigo_punto_venta}. No se guardo nada. "
                .RespuestaSiat::desde($respuesta, 'RespuestaCufd')->motivo(),
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
                // Ruta por la que se pidio (/v2 o /sfd), para el panel.
                'version' => $version,
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
            // Siempre en hora de Bolivia. Hoy el SIN la manda con -04:00, pero
            // si llegara en UTC (...Z) se guardaria la hora UTC tal cual: un
            // CUFD figuraria vigente 4 horas de mas y las facturas emitidas
            // con el ya vencido saldrian rechazadas.
            return Carbon::parse((string) $declarada)->setTimezone(config('app.timezone'));
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
