<?php

namespace App\Services\Pruebas;

use App\Exceptions\FacturaInvalidaException;
use App\Exceptions\SiatException;
use App\Jobs\AnularFacturaEnSiat;
use App\Jobs\EjecutarCasoPrueba;
use App\Jobs\EnviarPaqueteContingencia;
use App\Models\CasoPrueba;
use App\Models\Catalogo;
use App\Models\Cufd;
use App\Models\Cuis;
use App\Models\EjecucionPrueba;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\FacturaAnulada;
use App\Models\ProductoServicio;
use App\Models\PuntoVenta;
use App\Services\Catalogos\SincronizadorEmpresa;
use App\Services\Catalogos\SincronizadorGlobal;
use App\Services\Contingencia\GestorContingencia;
use App\Services\Factura\EmisorFactura;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\GestorCodigos;
use App\Services\Siat\RespuestaSiat;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Corre los casos de prueba del piloto en orden (seccion 12.2).
 *
 * Cada paso guarda su respuesta cruda y el tiempo. Si uno falla, la secuencia
 * se detiene ahi y puede reintentarse desde ese punto sin repetir los previos.
 *
 * Los pasos 1 al 10 son estructurales y se ejecutan enteros desde aca. Los
 * pasos 11 al 16 emiten documentos reales, y los datos que llevan (que venta,
 * que motivo de anulacion, que codigo de evento) los define la especificacion
 * que el SIN genera para cada contribuyente: se leen de casos_prueba.payload_ejemplo
 * y NO se inventan. Si falta el payload, el paso falla diciendo exactamente que
 * cargar.
 */
class EjecutorPruebas
{
    /**
     * Los servicios se resuelven del contenedor en vez de construirse a mano:
     * es lo que permite correr estas pruebas sin el SIAT al otro lado.
     */
    public function __construct(
        private readonly FabricaServicios $fabrica,
        private readonly SincronizadorGlobal $catalogosGlobales,
        private readonly SincronizadorEmpresa $catalogosEmpresa,
        private readonly EmisorFactura $emisor,
        private readonly GestorContingencia $contingencia,
        private readonly GestorCodigos $codigos,
        private readonly GeneradorPaquetePrueba $paquetes,
    ) {}

    /**
     * Corre las pruebas de UNA etapa del portal del SIN.
     *
     * Cada prueba se repite hasta completar sus 'pruebas esperadas', contando
     * las que ya salieron bien antes: reejecutar una etapa a medias no repite
     * lo que ya estaba. Una falla no corta la etapa, porque sus pruebas son
     * independientes entre si (cada una con su sucursal y punto de venta).
     *
     * @return array{ejecutadas: int, fallidas: int}
     */
    public function ejecutarEtapa(Empresa $empresa, int $etapa): array
    {
        $casos = CasoPrueba::where('fase', CasoPrueba::FASE_PILOTO)
            ->where('etapa', $etapa)
            ->orderBy('orden')
            ->get();

        $correctas = $this->correctasPorCaso($empresa);
        $ejecutadas = 0;
        $fallidas = 0;

        foreach ($casos as $caso) {
            $faltan = max(0, $caso->pruebas_esperadas - ($correctas[$caso->id] ?? 0));

            for ($i = 0; $i < $faltan; $i++) {
                $ejecucion = $this->ejecutarCaso($empresa, $caso);
                $ejecutadas++;

                // Si una falla, repetir esa misma prueba va a fallar igual: se
                // pasa a la siguiente y el error queda a la vista.
                if ($ejecucion->estado === EjecucionPrueba::ESTADO_FALLIDO) {
                    $fallidas++;

                    break;
                }
            }
        }

        return ['ejecutadas' => $ejecutadas, 'fallidas' => $fallidas];
    }

    /**
     * Ejecuciones exitosas de cada caso para la empresa: es lo que el portal
     * llama "pruebas correctas". Se cuentan TODAS, no solo la ultima, porque
     * el portal suma cada solicitud aceptada.
     *
     * @return array<int, int> caso_id => cantidad
     */
    public function correctasPorCaso(Empresa $empresa): array
    {
        return EjecucionPrueba::where('empresa_id', $empresa->id)
            ->where('estado', EjecucionPrueba::ESTADO_EXITOSO)
            ->selectRaw('caso_id, count(*) as total')
            ->groupBy('caso_id')
            ->pluck('total', 'caso_id')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    /**
     * Ejecuta un caso concreto y registra su ejecucion.
     */
    public function ejecutarCaso(Empresa $empresa, CasoPrueba $caso): EjecucionPrueba
    {
        $inicio = microtime(true);

        try {
            $respuesta = $this->invocarOperacion($empresa, $caso);
            $estado = EjecucionPrueba::ESTADO_EXITOSO;
            $datos = $this->normalizar($respuesta);
        } catch (SiatException|Throwable $e) {
            $estado = EjecucionPrueba::ESTADO_FALLIDO;
            // La de factura invalida trae el detalle aparte: sin el, el error
            // solo decia "no cumple las validaciones" sin decir cual.
            $datos = ['error' => $e instanceof FacturaInvalidaException
                ? $e->getMessage().' '.implode(' | ', $e->errores)
                : $e->getMessage()];
        }

        return EjecucionPrueba::create([
            'empresa_id' => $empresa->id,
            'caso_id' => $caso->id,
            'estado' => $estado,
            'respuesta' => $datos,
            'duracion_ms' => (int) ((microtime(true) - $inicio) * 1000),
            'ejecutado_en' => now(),
        ]);
    }

    /**
     * Mapea el tipo del caso a lo que hay que hacer. Los tipos son los que
     * carga CasosPruebaSeeder; si el SIN cambia el manual se editan alli.
     */
    private function invocarOperacion(Empresa $empresa, CasoPrueba $caso): mixed
    {
        return match ($caso->tipo) {
            // --- Pasos 1 a 5: comunicacion y codigos -------------------------
            'verificarComunicacion' => $this->fabrica->codigos($empresa)->verificarComunicacion(),
            'fechaHora' => $this->fabrica->sincronizacion($empresa)->fechaHora(
                $this->cuisVigente($empresa),
                (int) $this->primerPuntoVenta($empresa)->sucursal->codigo_sucursal,
                (int) $this->primerPuntoVenta($empresa)->codigo_punto_venta,
            ),
            'verificarNit' => $this->fabrica->codigos($empresa)->verificarNit(
                $empresa->nit,
                $this->cuisVigente($empresa),
                (int) $this->primerPuntoVenta($empresa)->sucursal->codigo_sucursal,
            ),
            'cuis' => $this->solicitarCuis($empresa),
            // Etapa I del portal: CUIS para la sucursal y el punto de venta
            // que fija cada prueba, no para el "primer" punto de venta.
            'solicitudCuis' => $this->solicitarCuisDeEtapa($empresa, $caso),
            // Etapa II del portal: un catalogo con el CUIS del punto de venta.
            'sincronizacionCatalogo' => $this->sincronizarCatalogoDeEtapa($empresa, $caso),
            // Etapa III del portal: CUFD para el punto de venta de la prueba.
            'solicitudCufd' => $this->solicitarCufdDeEtapa($empresa, $caso),
            // Etapa IV del portal: factura firmada y enviada en linea.
            'emisionIndividual' => $this->emitirFacturaDeEtapa($empresa, $caso),
            // Etapa V del portal: evento significativo con CUFD actual y del evento.
            'eventoSignificativo' => $this->registrarEventoDeEtapa($empresa, $caso),
            // Etapa VI del portal: paquete fuera de linea y su validacion.
            'paqueteContingencia' => $this->enviarPaqueteDeEtapa($empresa, $caso),
            'validacionPaquete' => $this->validarPaqueteDeEtapa($empresa, $caso),
            // Etapa VII del portal: anula una factura validada en la etapa IV.
            'anulacionEtapa' => $this->anularFacturaDeEtapa($empresa, $caso),
            // Etapa XI del portal: revierte una anulacion de la etapa VII.
            'reversionAnulacion' => $this->revertirAnulacionDeEtapa($empresa, $caso),
            'cufd' => $this->solicitarCufd($empresa),

            // --- Pasos 6 a 9: catalogos --------------------------------------
            // Se pasa el punto de venta y no el CUIS suelto: el SIN valida que
            // el CUIS corresponda a la sucursal y al codigo de la peticion.
            'sincronizarGlobales' => $this->catalogosGlobales
                ->sincronizarTodo($this->primerPuntoVenta($empresa)),
            'listaActividades' => ['actividades' => $this->catalogosEmpresa
                ->sincronizarActividades($this->primerPuntoVenta($empresa))],
            'listaProductos' => ['productos' => $this->catalogosEmpresa
                ->sincronizarProductos($this->primerPuntoVenta($empresa))],
            'listaLeyendas' => ['leyendas' => $this->catalogosEmpresa
                ->sincronizarLeyendas($this->primerPuntoVenta($empresa))],

            // --- Paso 10: estructura ------------------------------------------
            'registroPuntoVenta' => $this->registrarPuntoVenta($empresa),

            // --- Pasos 11 a 13: emision ---------------------------------------
            'recepcionFactura',
            'recepcionFacturaDescuento',
            'recepcionFacturaNit' => $this->emitirFacturaDePrueba($empresa, $caso),

            // --- Pasos 14 a 16: anulacion, evento y contingencia --------------
            'anulacionFactura' => $this->anularUltimaFactura($empresa, $caso),
            'registroEvento' => $this->registrarEvento($empresa, $caso),
            'recepcionPaquete' => $this->emitirEnContingencia($empresa),

            // --- Paso 17: cierre ----------------------------------------------
            'marcarAprobado' => $this->verificarPilotoCompleto($empresa, $caso),

            default => throw new SiatException("Caso '{$caso->tipo}' aun no implementado en el ejecutor."),
        };
    }

    /**
     * Solicita el CUIS y lo guarda como historial, igual que el panel: el paso
     * siguiente (CUFD) lo necesita vigente en la base, no solo en la respuesta.
     */
    private function solicitarCuis(Empresa $empresa): array
    {
        $puntoVenta = $this->primerPuntoVenta($empresa);
        $respuesta = $this->fabrica->codigos($empresa)->solicitarCuis($puntoVenta);

        $codigo = (string) data_get($respuesta, 'RespuestaCuis.codigo');

        if (blank($codigo)) {
            throw new SiatException('El SIAT no devolvio un codigo CUIS en la respuesta.');
        }

        Cuis::create([
            'punto_venta_id' => $puntoVenta->id,
            'codigo' => $codigo,
            'fecha_vigencia' => now()->addYear(),
        ]);

        return ['cuis' => $codigo];
    }

    /**
     * Codigo con el que el SIN avisa que ya habia un CUIS vigente y devuelve
     * ese mismo, sin emitir uno nuevo.
     */
    public const CUIS_YA_VIGENTE = 980;

    /**
     * Pide el CUIS con los parametros exactos de la prueba del portal.
     *
     * Lo que el portal cuenta es una solicitud con esa sucursal y ese punto de
     * venta en la que el SIN EMITE un CUIS: transaccion = true. Si ya habia uno
     * vigente responde transaccion = false con el codigo 980 y devuelve el
     * viejo; eso no suma en el portal, asi que aca tampoco es exito.
     * Comprobado en el piloto: dos solicitudes con 980 dejaron la prueba en 0.
     *
     * No hace falta tener el punto de venta configurado aca. Si existe, el
     * codigo se guarda igual (es valido) para las etapas siguientes.
     *
     * @return array<string, mixed>
     */
    private function solicitarCuisDeEtapa(Empresa $empresa, CasoPrueba $caso): array
    {
        [$codigoSucursal, $codigoPuntoVenta] = $this->codigosDePrueba($caso);

        $respuesta = $this->fabrica->codigos($empresa)->solicitarCuisPara($codigoSucursal, $codigoPuntoVenta);
        $leida = RespuestaSiat::desde($respuesta, 'RespuestaCuis');
        $codigo = (string) data_get($respuesta, 'RespuestaCuis.codigo');

        // El SIN rechaza con HTTP 200 y el codigo vacio: el motivo viene en
        // mensajesList y es lo unico que dice que corregir.
        if (blank($codigo)) {
            throw new SiatException('El SIN no devolvio CUIS: '.$leida->motivo());
        }

        $local = $this->puntoVentaLocal($empresa, $codigoSucursal, $codigoPuntoVenta);

        if ($local !== null) {
            $this->codigos->guardarCuis($local, $respuesta);
        }

        if (! $leida->aceptada) {
            $yaVigente = collect($leida->mensajes)->contains(fn (array $m): bool => (int) $m['codigo'] === self::CUIS_YA_VIGENTE);

            throw new SiatException($yaVigente
                ? "El SIN devolvio el CUIS que ya estaba vigente ({$codigo}) en vez de emitir uno: el portal NO lo cuenta como prueba. ".
                  'Para que emita uno nuevo hay que cerrar antes las operaciones del sistema en este punto de venta.'
                : "El SIN no emitio el CUIS: {$leida->motivo()}");
        }

        return [
            'codigoSucursal' => $codigoSucursal,
            'codigoPuntoVenta' => $codigoPuntoVenta,
            'cuis' => $codigo,
            'vigencia' => data_get($respuesta, 'RespuestaCuis.fechaVigencia'),
            'guardado_en_punto_venta_local' => $local !== null,
        ];
    }

    /**
     * Pide un catalogo con los parametros de la prueba de la etapa II.
     *
     * El CUIS sale del punto de venta local con esos codigos: el SIN valida
     * que corresponda a la sucursal y al punto de venta de la peticion, y la
     * etapa I es justamente la que lo dejo guardado.
     *
     * No se guarda el catalogo ni la respuesta entera: son 50 llamadas por
     * prueba y algunas listas tienen miles de filas. Para eso esta el paso de
     * sincronizacion real; aca basta la evidencia de que el SIN respondio.
     *
     * @return array<string, mixed>
     */
    private function sincronizarCatalogoDeEtapa(Empresa $empresa, CasoPrueba $caso): array
    {
        [$codigoSucursal, $codigoPuntoVenta] = $this->codigosDePrueba($caso);
        $operacion = (string) data_get($caso->payload_ejemplo, 'operacion');

        if (blank($operacion)) {
            throw new SiatException("La prueba '{$caso->nombre}' no indica la operacion del catalogo en su payload.");
        }

        $cuis = $this->puntoVentaLocal($empresa, $codigoSucursal, $codigoPuntoVenta)?->cuisVigente();

        if ($cuis === null) {
            throw new SiatException(
                "El punto de venta {$codigoPuntoVenta} de la sucursal {$codigoSucursal} no tiene CUIS vigente guardado: ".
                'configuralo en la ficha o corre la etapa I.',
            );
        }

        $respuesta = $this->fabrica->sincronizacion($empresa)
            ->parametrica($operacion, $cuis->codigo, $codigoSucursal, $codigoPuntoVenta);

        // El SIN rechaza con HTTP 200 y transaccion = false.
        $rechazo = RespuestaSiat::rechazoDeCatalogo($respuesta);

        if ($rechazo !== null) {
            throw new SiatException("El SIN rechazo '{$operacion}': {$rechazo}");
        }

        return [
            'operacion' => $operacion,
            'codigoSucursal' => $codigoSucursal,
            'codigoPuntoVenta' => $codigoPuntoVenta,
            'registros' => $this->contarRegistros($respuesta),
        ];
    }

    /**
     * Pide un CUFD con los parametros de la prueba de la etapa III.
     *
     * A diferencia del CUIS, el SIN emite un CUFD nuevo en cada solicitud, asi
     * que cada una cuenta. Igual se exige transaccion = true: es lo que suma
     * en el portal. Cada CUFD se guarda —el ultimo emitido es el que vale para
     * facturar— atado al CUIS con el que se pidio.
     *
     * @return array<string, mixed>
     */
    private function solicitarCufdDeEtapa(Empresa $empresa, CasoPrueba $caso): array
    {
        [$codigoSucursal, $codigoPuntoVenta] = $this->codigosDePrueba($caso);
        $local = $this->puntoVentaLocal($empresa, $codigoSucursal, $codigoPuntoVenta);
        $cuis = $local?->cuisVigente();

        if ($cuis === null) {
            throw new SiatException(
                "El punto de venta {$codigoPuntoVenta} de la sucursal {$codigoSucursal} no tiene CUIS vigente guardado: ".
                'configuralo en la ficha o corre la etapa I.',
            );
        }

        $respuesta = $this->fabrica->codigos($empresa)->solicitarCufd($local, $cuis->codigo);
        $leida = RespuestaSiat::desde($respuesta, 'RespuestaCufd');

        // Sin codigo y codigo de control no hay CUFD que guardar: el gestor
        // corta, y el motivo del SIN es mas util que el suyo.
        if (blank(data_get($respuesta, 'RespuestaCufd.codigo')) || blank(data_get($respuesta, 'RespuestaCufd.codigoControl'))) {
            throw new SiatException('El SIN no devolvio CUFD: '.$leida->motivo());
        }

        $cufd = $this->codigos->guardarCufd($local, $cuis, $respuesta);

        if (! $leida->aceptada) {
            throw new SiatException("El SIN no emitio el CUFD ({$cufd->codigo}): {$leida->motivo()}");
        }

        return [
            'codigoSucursal' => $codigoSucursal,
            'codigoPuntoVenta' => $codigoPuntoVenta,
            'cufd' => $cufd->codigo,
            'vigencia' => $cufd->fecha_vigencia?->toDateTimeString(),
        ];
    }

    /**
     * Codigo con el que el SIN responde que la factura quedo validada.
     */
    private const FACTURA_VALIDADA = '908';

    /**
     * Emite una factura de la etapa IV y la envia en el momento.
     *
     * Pasa por el MISMO EmisorFactura que la API (correlativo, CUF, XML,
     * firma): lo que se prueba es el sistema real, no un atajo. Solo cambia el
     * envio, que es sincrono para poder leer la respuesta del SIN aca: cuenta
     * solo si el SIN la valida (codigo 908). Si la observa, la factura queda
     * OBSERVADA y el motivo en la ejecucion.
     *
     * @return array<string, mixed>
     */
    private function emitirFacturaDeEtapa(Empresa $empresa, CasoPrueba $caso): array
    {
        [$codigoSucursal, $codigoPuntoVenta] = $this->codigosDePrueba($caso);
        $local = $this->puntoVentaLocal($empresa, $codigoSucursal, $codigoPuntoVenta);
        $cuis = $local?->cuisVigente();

        if ($cuis === null) {
            throw new SiatException(
                "El punto de venta {$codigoPuntoVenta} de la sucursal {$codigoSucursal} no tiene CUIS vigente guardado: ".
                'configuralo en la ficha o corre la etapa I.',
            );
        }

        $this->asegurarCatalogos($empresa, $local);

        $factura = $this->emisor->emitir(
            $empresa,
            $this->ventaDePrueba($empresa, $caso, $codigoSucursal, $codigoPuntoVenta),
            encolarEnvio: false,
        );

        $respuesta = RespuestaSiat::desde(
            $this->fabrica->facturacion($empresa)
                ->recepcionarFactura($factura, (string) $factura->cufd?->codigo, (string) $local->cuisDe($factura->cufd)?->codigo),
        );

        $validada = $respuesta->aceptada && $respuesta->codigoEstado === self::FACTURA_VALIDADA;

        $factura->update([
            'estado' => $validada ? Factura::ESTADO_VALIDADA : Factura::ESTADO_OBSERVADA,
            'codigo_recepcion' => $respuesta->codigoRecepcion,
            'codigo_estado_siat' => $respuesta->codigoEstado,
            'enviada_en' => now(),
            'validada_en' => $validada ? now() : null,
        ]);

        if (! $validada) {
            throw new SiatException(
                "El SIN no valido la factura {$factura->numero_factura} (estado {$respuesta->codigoEstado}): {$respuesta->motivo()}",
            );
        }

        return [
            'cuf' => $factura->cuf,
            'numero_factura' => $factura->numero_factura,
            'codigo_estado' => $respuesta->codigoEstado,
            'codigo_recepcion' => $respuesta->codigoRecepcion,
        ];
    }

    /**
     * Minutos hacia atras desde ahora en los que termina, como tarde, un evento
     * de prueba. El SIN no acepta un evento que todavia no termino.
     */
    private const MINUTOS_FIN_EVENTO = 2;

    /**
     * Margen entre la emision del cufdEvento y el inicio del primer evento,
     * por si el reloj del SIN y el nuestro no coinciden al segundo.
     */
    private const SEGUNDOS_TRAS_EMISION = 5;

    /**
     * Registra un evento significativo de la etapa V.
     *
     * @return array<string, mixed>
     */
    private function registrarEventoDeEtapa(Empresa $empresa, CasoPrueba $caso): array
    {
        return $this->registrarEventoPrueba($empresa, $caso)['respuesta'];
    }

    /**
     * Registra un evento significativo con los parametros de una prueba. Lo
     * usan la etapa V (solo el evento) y la VI (el evento que justifica cada
     * paquete).
     *
     * Reglas tomadas del sistema de referencia que ya paso esta etapa ante el
     * SIN (proyecto ventas, runPilotSignificantEvent):
     *   - 'cufd' es el CUFD vigente y 'cufdEvento' uno ANTERIOR, distinto: el
     *     del periodo en que se supone que se cayo la conexion.
     *   - el evento dura un minuto, ya termino, y no se pisa con otro del mismo
     *     punto de venta.
     *
     * @return array{respuesta: array<string, mixed>, puntoVenta: PuntoVenta, cuis: Cuis, cufd: Cufd, cufdEvento: Cufd, inicio: Carbon, fin: Carbon}
     */
    private function registrarEventoPrueba(Empresa $empresa, CasoPrueba $caso): array
    {
        [$codigoSucursal, $codigoPuntoVenta] = $this->codigosDePrueba($caso);
        $local = $this->puntoVentaLocal($empresa, $codigoSucursal, $codigoPuntoVenta);
        $cuis = $local?->cuisVigente();
        $actual = $local?->cufdVigente();

        if ($cuis === null || $actual === null) {
            throw new SiatException(
                "El punto de venta {$codigoPuntoVenta} de la sucursal {$codigoSucursal} necesita CUIS y CUFD vigentes: corre las etapas I y III.",
            );
        }

        // El MAS ANTIGUO de los vigentes, no el ultimo: el evento tiene que caer
        // despues de que el SIN emitio el cufdEvento (si no, responde 984), asi
        // que cuanto mas viejo, mas ventana de tiempo hay para ubicar los 140
        // eventos de las etapas V y VI. Del mismo CUIS que el vigente, porque
        // el SIN ata cada CUFD al CUIS que lo genero.
        $delEvento = $local->cufds()
            ->where('id', '<', $actual->id)
            ->where('codigo', '!=', $actual->codigo)
            ->where('fecha_vigencia', '>', now())
            ->when($actual->cuis_id !== null, fn ($q) => $q->where('cuis_id', $actual->cuis_id))
            ->oldest('id')
            ->first();

        // Sin uno anterior, el vigente pasa a ser el del evento y se pide otro.
        if ($delEvento === null) {
            $delEvento = $actual;
            $actual = $this->codigos->solicitarCufd($local);
        }

        // El CUIS va atado al CUFD vigente (ver PuntoVenta::cuisDe).
        $cuis = $local->cuisDe($actual);

        [$inicio, $fin] = $this->rangoDeEvento($empresa, $codigoPuntoVenta, $delEvento);

        $respuesta = RespuestaSiat::desde(
            $this->fabrica->operaciones($empresa)->registrarEvento([
                'codigoSucursal' => $codigoSucursal,
                'codigoPuntoVenta' => $codigoPuntoVenta,
                'codigoMotivoEvento' => (int) data_get($caso->payload_ejemplo, 'codigoMotivoEvento'),
                'descripcion' => (string) data_get($caso->payload_ejemplo, 'descripcion'),
                'cuis' => $cuis->codigo,
                'cufd' => $actual->codigo,
                'cufdEvento' => $delEvento->codigo,
                'fechaHoraInicioEvento' => $inicio->format('Y-m-d\TH:i:s.v'),
                'fechaHoraFinEvento' => $fin->format('Y-m-d\TH:i:s.v'),
            ]),
            'RespuestaListaEventos',
        );

        if (! $respuesta->aceptada) {
            throw new SiatException("El SIN no registro el evento: {$respuesta->motivo()}");
        }

        return [
            'respuesta' => [
                'codigoPuntoVenta' => $codigoPuntoVenta,
                'codigoRecepcionEventoSignificativo' => data_get($respuesta->crudo, 'codigoRecepcionEventoSignificativo'),
                'cufd' => $actual->codigo,
                'cufdEvento' => $delEvento->codigo,
                'inicio' => $inicio->toDateTimeString(),
                'fin' => $fin->toDateTimeString(),
            ],
            'puntoVenta' => $local,
            'cuis' => $cuis,
            'cufd' => $actual,
            'cufdEvento' => $delEvento,
            'inicio' => $inicio,
            'fin' => $fin,
        ];
    }

    /**
     * Tipos de prueba que registran un evento: comparten la linea de tiempo
     * del punto de venta, asi que ninguno puede pisarse con otro.
     *
     * @var list<string>
     */
    private const TIPOS_CON_EVENTO = ['eventoSignificativo', 'paqueteContingencia'];

    /**
     * Inicio y fin del proximo evento de prueba de un punto de venta.
     *
     * El SIN exige que el evento caiga DENTRO de la vida del cufdEvento: uno
     * que empieza antes de que ese CUFD se emitiera vuelve con "[984] EL EVENTO
     * SIGNIFICATIVO NO CORRESPONDE AL CUFD DEL EVENTO REGISTRADO". Antes los
     * eventos se apilaban hacia ATRAS desde ahora, un minuto cada uno, y a los
     * ~20 cruzaban la emision del CUFD: desde ahi fallaban todos.
     *
     * Ahora van hacia ADELANTE: el primer hueco libre de un minuto despues de
     * la emision del cufdEvento, sin pisar los eventos ya registrados de ese
     * punto de venta, y que ya haya terminado.
     *
     * @return array{0: Carbon, 1: Carbon}
     *
     * @throws SiatException si todavia no hay hueco: hay que esperar.
     */
    private function rangoDeEvento(Empresa $empresa, int $codigoPuntoVenta, Cufd $cufdEvento): array
    {
        $casosDelPuntoVenta = CasoPrueba::whereIn('tipo', self::TIPOS_CON_EVENTO)->get()
            ->filter(fn (CasoPrueba $c): bool => (int) data_get($c->payload_ejemplo, 'codigoPuntoVenta') === $codigoPuntoVenta)
            ->pluck('id');

        $ocupados = EjecucionPrueba::where('empresa_id', $empresa->id)
            ->whereIn('caso_id', $casosDelPuntoVenta)
            ->where('estado', EjecucionPrueba::ESTADO_EXITOSO)
            ->get()
            ->map(fn (EjecucionPrueba $e): array => [data_get($e->respuesta, 'inicio'), data_get($e->respuesta, 'fin')])
            ->filter(fn (array $r): bool => filled($r[0]))
            ->map(fn (array $r): array => [
                Carbon::parse($r[0]),
                // Las ejecuciones viejas solo guardaban el inicio.
                filled($r[1]) ? Carbon::parse($r[1]) : Carbon::parse($r[0])->addMinute(),
            ])
            ->sortBy(fn (array $r) => $r[0]->getTimestamp())
            ->values();

        $inicio = $this->emisionDe($cufdEvento)->addSeconds(self::SEGUNDOS_TRAS_EMISION);

        foreach ($ocupados as [$desde, $hasta]) {
            // Entra entero antes de este evento: listo.
            if ($inicio->copy()->addMinute()->lessThan($desde)) {
                break;
            }

            if ($hasta->greaterThanOrEqualTo($inicio)) {
                $inicio = $hasta->copy()->addSecond();
            }
        }

        $fin = $inicio->copy()->addMinute();
        $limite = now()->subMinutes(self::MINUTOS_FIN_EVENTO);

        if ($fin->greaterThan($limite)) {
            $espera = (int) ceil($limite->diffInSeconds($fin) / 60);

            throw new SiatException(
                "Todavia no hay lugar para otro evento en el punto de venta {$codigoPuntoVenta}: tiene que caer despues de la emision del CUFD del evento y ya haber terminado. Reintenta en {$espera} min.",
            );
        }

        return [$inicio, $fin];
    }

    /**
     * Momento en que el SIN emitio un CUFD.
     *
     * El SIN declara la vigencia (emision + 24 h); nuestro created_at puede
     * diferir unos segundos por el reloj. Se toma el mas tardio de los dos.
     */
    private function emisionDe(Cufd $cufd): Carbon
    {
        $segunElSin = $cufd->fecha_vigencia?->copy()->subDay();
        $segunNosotros = $cufd->created_at?->copy() ?? now();

        return ($segunElSin !== null && $segunElSin->greaterThan($segunNosotros) ? $segunElSin : $segunNosotros)
            ->startOfSecond();
    }

    /**
     * Envia un paquete de facturas fuera de linea (etapa VI, pruebas 1 a 14).
     *
     * El flujo es el de una contingencia real: se registra el evento, las
     * facturas se emiten "durante" el evento (fechas dentro de su rango, CUFD
     * del evento, codigoEmision 2) y viajan juntas en un TAR.GZ. Cuenta si el
     * SIN recibe el paquete y devuelve su codigo de recepcion; la validacion
     * es otra prueba (15 y 16) porque el SIN la procesa despues.
     *
     * @return array<string, mixed>
     */
    private function enviarPaqueteDeEtapa(Empresa $empresa, CasoPrueba $caso): array
    {
        $cantidad = (int) data_get($caso->payload_ejemplo, 'cantidadFacturas');

        if ($cantidad < 1 || $cantidad > 500) {
            throw new SiatException("La prueba '{$caso->nombre}' necesita cantidadFacturas entre 1 y 500 en su payload.");
        }

        [$codigoSucursal, $codigoPuntoVenta] = $this->codigosDePrueba($caso);
        $local = $this->puntoVentaLocal($empresa, $codigoSucursal, $codigoPuntoVenta);

        if ($local === null) {
            throw new SiatException("El punto de venta {$codigoPuntoVenta} no esta configurado en este sistema.");
        }

        // Catalogos y venta ANTES del evento: si falta algo, no se gasta un
        // evento registrado en el SIN para nada.
        $this->asegurarCatalogos($empresa, $local);
        $venta = $this->ventaDePrueba($empresa, $caso, $codigoSucursal, $codigoPuntoVenta);

        $evento = $this->registrarEventoPrueba($empresa, $caso);
        $codigoEvento = $evento['respuesta']['codigoRecepcionEventoSignificativo'];

        $paquete = $this->paquetes->armar($empresa, $local, $evento['cufdEvento'], $venta, $cantidad, $evento['inicio']);

        $respuesta = RespuestaSiat::desde(
            $this->fabrica->facturacion($empresa)->recepcionarPaquete([
                'codigoSucursal' => $codigoSucursal,
                'codigoPuntoVenta' => $codigoPuntoVenta,
                'codigoDocumentoSector' => config('siat.codigos.documento_sector'),
                'codigoEmision' => Factura::EMISION_CONTINGENCIA,
                'tipoFacturaDocumento' => config('siat.codigos.tipo_factura_documento'),
                'cufd' => $evento['cufd']->codigo,
                'cuis' => $evento['cuis']->codigo,
                // El CAFC es de la modalidad computarizada: aca no aplica.
                'cafc' => null,
                'cantidadFacturas' => $cantidad,
                'codigoEvento' => $codigoEvento,
            ], $paquete['tar']),
        );

        if (! $respuesta->aceptada || blank($respuesta->codigoRecepcion)) {
            throw new SiatException("El SIN no recibio el paquete (evento {$codigoEvento}): {$respuesta->motivo()}");
        }

        // 'inicio' queda guardado: es lo que usa rangoDeEvento() para que el
        // proximo evento de este punto de venta no se pise con este.
        return $evento['respuesta'] + [
            'codigoRecepcion' => $respuesta->codigoRecepcion,
            'codigoEstado' => $respuesta->codigoEstado,
            'cantidadFacturas' => $cantidad,
            'facturas' => "{$paquete['desde']} a {$paquete['hasta']}",
        ];
    }

    /**
     * Codigo con el que el SIN informa que un paquete ya fue procesado y
     * validado.
     */
    private const PAQUETE_VALIDADO = '908';

    /**
     * Valida el siguiente paquete enviado y todavia sin validar de ese punto de
     * venta (etapa VI, pruebas 15 y 16).
     *
     * Si el SIN todavia lo esta procesando (901) la prueba falla sin consumir
     * el paquete: la siguiente vez se vuelve a preguntar por el mismo.
     *
     * @return array<string, mixed>
     */
    private function validarPaqueteDeEtapa(Empresa $empresa, CasoPrueba $caso): array
    {
        [$codigoSucursal, $codigoPuntoVenta] = $this->codigosDePrueba($caso);
        $local = $this->puntoVentaLocal($empresa, $codigoSucursal, $codigoPuntoVenta);
        $cufd = $local?->cufdVigente();
        // El CUIS con el que se emitio ese CUFD, no el mas nuevo.
        $cuis = $local?->cuisDe($cufd);

        if ($cuis === null || $cufd === null) {
            throw new SiatException("El punto de venta {$codigoPuntoVenta} necesita CUIS y CUFD vigentes.");
        }

        $codigoRecepcion = $this->siguientePaqueteSinValidar($empresa, $caso, $codigoPuntoVenta);

        if ($codigoRecepcion === null) {
            throw new SiatException(
                "No hay paquetes enviados sin validar del punto de venta {$codigoPuntoVenta}: corre antes las pruebas de envio de paquetes.",
            );
        }

        $respuesta = RespuestaSiat::desde(
            $this->fabrica->facturacion($empresa)->validarRecepcionPaquete([
                'codigoSucursal' => $codigoSucursal,
                'codigoPuntoVenta' => $codigoPuntoVenta,
                'codigoDocumentoSector' => config('siat.codigos.documento_sector'),
                'codigoEmision' => Factura::EMISION_CONTINGENCIA,
                'tipoFacturaDocumento' => config('siat.codigos.tipo_factura_documento'),
                'cufd' => $cufd->codigo,
                'cuis' => $cuis->codigo,
                'codigoRecepcion' => $codigoRecepcion,
            ]),
        );

        if (! $respuesta->aceptada || $respuesta->codigoEstado !== self::PAQUETE_VALIDADO) {
            throw new SiatException(
                "El paquete {$codigoRecepcion} no esta validado (estado {$respuesta->codigoEstado}): {$respuesta->motivo()}",
            );
        }

        return [
            'codigoPuntoVenta' => $codigoPuntoVenta,
            'codigoRecepcion' => $codigoRecepcion,
            'codigoEstado' => $respuesta->codigoEstado,
        ];
    }

    /**
     * Anula una factura de la etapa VII.
     *
     * Toma la factura VALIDADA mas antigua de ese punto de venta: las de la
     * etapa IV, que son justo 125 por punto de venta. Va con el CUFD vigente
     * (el portal pide "su CUFD valido") y el motivo se busca por su nombre en
     * el catalogo del SIN, para no fijar un numero que el SIN podria cambiar.
     *
     * Cuenta si el SIN acepta la anulacion; entonces la factura queda ANULADA
     * y con su registro de anulacion confirmado, igual que por la API.
     *
     * @return array<string, mixed>
     */
    private function anularFacturaDeEtapa(Empresa $empresa, CasoPrueba $caso): array
    {
        [$codigoSucursal, $codigoPuntoVenta] = $this->codigosDePrueba($caso);
        $local = $this->puntoVentaLocal($empresa, $codigoSucursal, $codigoPuntoVenta);
        $cufd = $local?->cufdVigente();
        // El CUIS con el que se emitio ese CUFD, no el mas nuevo.
        $cuis = $local?->cuisDe($cufd);

        if ($cuis === null || $cufd === null) {
            throw new SiatException("El punto de venta {$codigoPuntoVenta} necesita CUIS y CUFD vigentes.");
        }

        $factura = Factura::where('empresa_id', $empresa->id)
            ->where('punto_venta_id', $local->id)
            ->where('estado', Factura::ESTADO_VALIDADA)
            ->orderBy('id')
            ->first();

        if ($factura === null) {
            throw new SiatException(
                "No hay facturas validadas del punto de venta {$codigoPuntoVenta} para anular: corre antes la etapa IV.",
            );
        }

        $motivo = $this->codigoMotivoAnulacion($local, (string) data_get($caso->payload_ejemplo, 'codigoMotivo'));

        $respuesta = RespuestaSiat::desde(
            $this->fabrica->facturacion($empresa)->anular($factura, $motivo, $cufd->codigo, $cuis->codigo),
        );

        if (! $respuesta->aceptada) {
            throw new SiatException(
                "El SIN no anulo la factura {$factura->numero_factura} (estado {$respuesta->codigoEstado}): {$respuesta->motivo()}",
            );
        }

        FacturaAnulada::updateOrCreate(
            ['factura_id' => $factura->id],
            [
                'motivo' => $motivo,
                'anulada_en' => now(),
                'estado' => FacturaAnulada::ESTADO_CONFIRMADA,
                'estado_anterior' => $factura->estado,
                'codigo_recepcion' => $respuesta->codigoRecepcion,
            ],
        );

        $factura->update(['estado' => Factura::ESTADO_ANULADA]);

        return [
            'cuf' => $factura->cuf,
            'numero_factura' => $factura->numero_factura,
            'codigoMotivo' => $motivo,
            'codigoEstado' => $respuesta->codigoEstado,
        ];
    }

    /**
     * Revierte una anulacion de la etapa XI.
     *
     * Toma la factura ANULADA mas antigua de ese punto de venta cuya anulacion
     * el SIN confirmo (las de la etapa VII). Si el SIN acepta, la factura vuelve
     * al estado que tenia y la anulacion queda REVERTIDA, como historial.
     *
     * @return array<string, mixed>
     */
    private function revertirAnulacionDeEtapa(Empresa $empresa, CasoPrueba $caso): array
    {
        [$codigoSucursal, $codigoPuntoVenta] = $this->codigosDePrueba($caso);
        $local = $this->puntoVentaLocal($empresa, $codigoSucursal, $codigoPuntoVenta);
        $cufd = $local?->cufdVigente();
        // El CUIS con el que se emitio ese CUFD, no el mas nuevo.
        $cuis = $local?->cuisDe($cufd);

        if ($cuis === null || $cufd === null) {
            throw new SiatException("El punto de venta {$codigoPuntoVenta} necesita CUIS y CUFD vigentes.");
        }

        $factura = Factura::where('empresa_id', $empresa->id)
            ->where('punto_venta_id', $local->id)
            ->where('estado', Factura::ESTADO_ANULADA)
            ->whereHas('anulacion', fn ($q) => $q->where('estado', FacturaAnulada::ESTADO_CONFIRMADA))
            ->with('anulacion')
            ->orderBy('id')
            ->first();

        if ($factura === null) {
            throw new SiatException(
                "No hay facturas anuladas del punto de venta {$codigoPuntoVenta} para revertir: corre antes la etapa VII.",
            );
        }

        $respuesta = RespuestaSiat::desde(
            $this->fabrica->facturacion($empresa)->revertirAnulacion($factura, $cufd->codigo, $cuis->codigo),
        );

        if (! $respuesta->aceptada) {
            throw new SiatException(
                "El SIN no revirtio la anulacion de la factura {$factura->numero_factura} (estado {$respuesta->codigoEstado}): {$respuesta->motivo()}",
            );
        }

        $factura->anulacion->update(['estado' => FacturaAnulada::ESTADO_REVERTIDA]);
        $factura->update(['estado' => $factura->anulacion->estado_anterior ?? Factura::ESTADO_VALIDADA]);

        return [
            'cuf' => $factura->cuf,
            'numero_factura' => $factura->numero_factura,
            'codigoEstado' => $respuesta->codigoEstado,
        ];
    }

    /**
     * Codigo del motivo de anulacion a partir de su descripcion en el catalogo.
     */
    private function codigoMotivoAnulacion(PuntoVenta $puntoVenta, string $descripcion): int
    {
        if (! Catalogo::deTipo('motivos_anulacion')->exists()) {
            $this->catalogosGlobales->sincronizarTodo($puntoVenta);
        }

        $codigo = Catalogo::deTipo('motivos_anulacion')
            ->where('descripcion', $descripcion)
            ->value('codigo_clasificador');

        if (blank($codigo)) {
            throw new SiatException("El motivo de anulacion '{$descripcion}' no esta en el catalogo del SIN sincronizado.");
        }

        return (int) $codigo;
    }

    /**
     * Codigo de recepcion del paquete enviado mas antiguo de ese punto de venta
     * que todavia no tiene una validacion exitosa.
     */
    private function siguientePaqueteSinValidar(Empresa $empresa, CasoPrueba $validacion, int $codigoPuntoVenta): ?string
    {
        $exitosas = fn (array $tipos) => EjecucionPrueba::where('empresa_id', $empresa->id)
            ->where('estado', EjecucionPrueba::ESTADO_EXITOSO)
            ->whereIn('caso_id', CasoPrueba::whereIn('tipo', $tipos)->get()
                ->filter(fn (CasoPrueba $c): bool => (int) data_get($c->payload_ejemplo, 'codigoPuntoVenta') === $codigoPuntoVenta)
                ->pluck('id'))
            ->orderBy('id')
            ->get()
            ->map(fn (EjecucionPrueba $e) => (string) data_get($e->respuesta, 'codigoRecepcion'))
            ->filter();

        $validados = $exitosas([$validacion->tipo]);

        return $exitosas(['paqueteContingencia'])->first(fn (string $codigo): bool => ! $validados->contains($codigo));
    }

    /**
     * Sincroniza y GUARDA los catalogos que la factura necesita, si faltan.
     *
     * La etapa II solo prueba las llamadas, no guarda nada. Sin productos
     * homologados no hay actividad economica, sin leyendas la cabecera sale
     * vacia, y sin unidades de medida no hay con que armar el detalle: el SIN
     * rechazaria las 250 facturas por lo mismo. Se hace una sola vez.
     */
    private function asegurarCatalogos(Empresa $empresa, PuntoVenta $puntoVenta): void
    {
        if (! ProductoServicio::where('empresa_id', $empresa->id)->exists()) {
            $this->catalogosEmpresa->sincronizarTodo($puntoVenta);
        }

        if (! Catalogo::deTipo('unidades_medida')->exists()) {
            $this->catalogosGlobales->sincronizarTodo($puntoVenta);
        }
    }

    /**
     * Venta minima y valida para una factura de prueba.
     *
     * El portal no pide una venta concreta: cuenta facturas validadas. Se toma
     * el primer producto homologado del NIT y la primera unidad de medida del
     * catalogo, para que los codigos sean siempre del SIN y no inventados.
     * Si el caso trae 'venta' en su payload, esos datos mandan.
     *
     * @return array<string, mixed>
     */
    private function ventaDePrueba(Empresa $empresa, CasoPrueba $caso, int $codigoSucursal, int $codigoPuntoVenta): array
    {
        $producto = ProductoServicio::where('empresa_id', $empresa->id)->orderBy('id')->first();
        $unidad = Catalogo::deTipo('unidades_medida')->orderBy('codigo_clasificador')->value('codigo_clasificador');

        if ($producto === null || blank($unidad)) {
            throw new SiatException('No hay productos homologados o unidades de medida sincronizados: no se puede armar la factura.');
        }

        $venta = [
            'sucursal' => $codigoSucursal,
            'punto_venta' => $codigoPuntoVenta,
            'comprador' => [
                // 1 = CI y 1 = efectivo en los catalogos del SIN.
                'tipo_documento' => 1,
                'numero_documento' => '1234567',
                'razon_social' => 'PRUEBA PILOTO',
            ],
            'metodo_pago' => 1,
            'usuario' => 'piloto',
            'items' => [[
                'codigo_producto_sin' => (int) $producto->codigo_producto,
                'codigo_interno' => 'PILOTO-1',
                'descripcion' => mb_substr((string) $producto->descripcion, 0, 500) ?: 'PRODUCTO DE PRUEBA',
                'cantidad' => 1,
                'unidad_medida' => (int) $unidad,
                'precio_unitario' => 10,
            ]],
        ];

        return array_replace_recursive($venta, (array) data_get($caso->payload_ejemplo, 'venta', []));
    }

    /**
     * Cantidad de filas que trajo un catalogo. La lista viene bajo un nodo que
     * cambia de nombre por operacion; se cuenta la primera que aparezca.
     * Una lista de un solo elemento llega como objeto suelto, y la fecha y
     * hora no trae lista: en los dos casos es un registro.
     */
    private function contarRegistros(mixed $respuesta): int
    {
        $propiedades = is_object($respuesta) ? get_object_vars($respuesta) : (array) $respuesta;
        $cuerpo = count($propiedades) === 1 ? reset($propiedades) : $respuesta;

        foreach ((array) $cuerpo as $valor) {
            if (is_array($valor)) {
                return count($valor);
            }
        }

        return 1;
    }

    /**
     * Encola, una ejecucion por job, lo que le falta a cada prueba de la etapa.
     *
     * Para etapas grandes: la II son 36 pruebas x 50 = 1800 llamadas, horas de
     * SOAP que no entran en un request HTTP. Un job por llamada (1 a 4 s) no se
     * acerca al retry_after de la cola, asi que ningun worker lo toma dos veces.
     *
     * @return int cantidad de jobs encolados
     */
    public function encolarEtapa(Empresa $empresa, int $etapa): int
    {
        $casos = CasoPrueba::where('fase', CasoPrueba::FASE_PILOTO)
            ->where('etapa', $etapa)
            ->orderBy('orden')
            ->get();

        return $casos->sum(fn (CasoPrueba $caso): int => $this->encolarCaso($empresa, $caso));
    }

    /**
     * Encola lo que le falta a una prueba para llegar a sus esperadas.
     */
    public function encolarCaso(Empresa $empresa, CasoPrueba $caso): int
    {
        $faltan = $this->faltantes($empresa, $caso);
        $despachadoEn = now()->toDateTimeString();

        for ($i = 0; $i < $faltan; $i++) {
            EjecutarCasoPrueba::dispatch($empresa->id, $caso->id, $despachadoEn);
        }

        return $faltan;
    }

    /**
     * Pruebas que le faltan a un caso para completar sus esperadas.
     */
    public function faltantes(Empresa $empresa, CasoPrueba $caso): int
    {
        return max(0, $caso->pruebas_esperadas - ($this->correctasPorCaso($empresa)[$caso->id] ?? 0));
    }

    /**
     * Cierra las operaciones del sistema en el punto de venta de una prueba
     * (cierreOperacionesSistema del WSDL de FacturacionOperaciones).
     *
     * Existe para destrabar la etapa I: mientras el punto de venta tenga un
     * CUIS vigente, el SIN no emite otro y la prueba no avanza. NO es una
     * prueba del portal, por eso no deja ejecucion: el resultado vuelve al
     * operador para que decida el paso siguiente.
     *
     * El CUIS que exige la operacion se obtiene pidiendolo: el SIN devuelve el
     * vigente con el 980, que es justo el que hay que cerrar.
     *
     * @return array{cerrado: bool, cuis: string, motivo: string}
     */
    public function cerrarOperacionesDePrueba(Empresa $empresa, CasoPrueba $caso): array
    {
        [$codigoSucursal, $codigoPuntoVenta] = $this->codigosDePrueba($caso);

        $cuis = (string) data_get(
            $this->fabrica->codigos($empresa)->solicitarCuisPara($codigoSucursal, $codigoPuntoVenta),
            'RespuestaCuis.codigo',
        );

        if (blank($cuis)) {
            throw new SiatException('No hay CUIS que cerrar: el SIN no devolvio ninguno para ese punto de venta.');
        }

        $respuesta = RespuestaSiat::desde(
            $this->fabrica->operaciones($empresa)->cerrarOperacionesSistema($codigoSucursal, $codigoPuntoVenta, $cuis),
            'RespuestaCierreSistemas',
        );

        return [
            'cerrado' => $respuesta->aceptada,
            'cuis' => $cuis,
            'motivo' => $respuesta->aceptada ? '' : $respuesta->motivo(),
        ];
    }

    /**
     * Sucursal y punto de venta que fija una prueba del portal.
     *
     * @return array{0: int, 1: int}
     */
    private function codigosDePrueba(CasoPrueba $caso): array
    {
        $parametros = $this->payloadDe($caso, 'codigoSucursal y codigoPuntoVenta');

        return [(int) ($parametros['codigoSucursal'] ?? 0), (int) ($parametros['codigoPuntoVenta'] ?? 0)];
    }

    private function puntoVentaLocal(Empresa $empresa, int $codigoSucursal, int $codigoPuntoVenta): ?PuntoVenta
    {
        return PuntoVenta::query()
            ->whereHas('sucursal', fn ($q) => $q->where('empresa_id', $empresa->id)
                ->where('codigo_sucursal', $codigoSucursal))
            ->where('codigo_punto_venta', $codigoPuntoVenta)
            ->first();
    }

    /**
     * Solicita el CUFD y lo guarda. Su codigo_control es insumo del CUF, asi
     * que sin este paso los de emision no pueden correr.
     */
    private function solicitarCufd(Empresa $empresa): array
    {
        $puntoVenta = $this->primerPuntoVenta($empresa);
        $respuesta = $this->fabrica->codigos($empresa)
            ->solicitarCufd($puntoVenta, $this->cuisVigente($empresa));

        $codigo = (string) data_get($respuesta, 'RespuestaCufd.codigo');
        $codigoControl = (string) data_get($respuesta, 'RespuestaCufd.codigoControl');

        if (blank($codigo) || blank($codigoControl)) {
            throw new SiatException('El SIAT no devolvio codigo y codigo de control del CUFD.');
        }

        Cufd::create([
            'punto_venta_id' => $puntoVenta->id,
            'codigo' => $codigo,
            'codigo_control' => $codigoControl,
            'direccion' => (string) data_get($respuesta, 'RespuestaCufd.direccion'),
            'fecha_vigencia' => now()->addDay(),
        ]);

        return ['cufd' => $codigo];
    }

    /**
     * Emite una factura de prueba con la venta que define el caso.
     *
     * La venta NO se inventa: cada paso del piloto exige un documento concreto
     * (contado en efectivo, con descuento, a NIT de empresa) que describe la
     * especificacion del SIN para ese contribuyente. Se carga en el
     * payload_ejemplo del caso.
     */
    private function emitirFacturaDePrueba(Empresa $empresa, CasoPrueba $caso): array
    {
        $venta = $this->payloadDe($caso, 'la venta a emitir');

        // Referencia unica por ejecucion: sin esto, reintentar el paso
        // devolveria la factura anterior por idempotencia en vez de emitir.
        $venta['referencia_externa'] = "PILOTO-{$caso->id}-".now()->getTimestampMs();

        $factura = $this->emisor->emitir($empresa, $venta);

        // La transmision al SIN la hace EnviarFacturaAlSiat en segundo plano:
        // aca la factura ya quedo emitida, firmada y con su CUF.
        return [
            'cuf' => $factura->cuf,
            'numero_factura' => $factura->numero_factura,
            'estado' => $factura->estado,
        ];
    }

    /**
     * Anula la ultima factura emitida del cliente, con el motivo del catalogo
     * del SIN que indique el caso.
     */
    private function anularUltimaFactura(Empresa $empresa, CasoPrueba $caso): array
    {
        $factura = Factura::where('empresa_id', $empresa->id)->latest('id')->first();

        if ($factura === null) {
            throw new SiatException('No hay ninguna factura emitida: corre antes los pasos de emision.');
        }

        $motivo = data_get($caso->payload_ejemplo, 'motivo');

        if (blank($motivo)) {
            throw new SiatException(
                "El caso '{$caso->nombre}' necesita el codigo de motivo de anulacion en su payload_ejemplo ".
                '(ej: {"motivo": 1}). Es un codigo del catalogo del SIN: no se deduce.',
            );
        }

        FacturaAnulada::updateOrCreate(
            ['factura_id' => $factura->id],
            ['motivo' => (int) $motivo, 'anulada_en' => now()],
        );

        $factura->update(['estado' => Factura::ESTADO_ANULADA]);

        AnularFacturaEnSiat::dispatch($factura->id);

        return ['cuf_anulado' => $factura->cuf, 'motivo' => (int) $motivo];
    }

    /**
     * Registra un evento significativo. Los codigos de evento son del catalogo
     * del SIN, asi que el caso debe traerlos en su payload_ejemplo.
     */
    private function registrarEvento(Empresa $empresa, CasoPrueba $caso): mixed
    {
        $datos = $this->payloadDe($caso, 'los datos del evento significativo');

        return $this->fabrica->operaciones($empresa)->registrarEvento($datos);
    }

    /**
     * Deriva la ultima factura a contingencia y encola el envio del paquete,
     * que es exactamente el camino que sigue una caida real del SIAT.
     */
    private function emitirEnContingencia(Empresa $empresa): array
    {
        $factura = Factura::where('empresa_id', $empresa->id)
            ->where('estado', '!=', Factura::ESTADO_ANULADA)
            ->latest('id')
            ->first();

        if ($factura === null) {
            throw new SiatException('No hay ninguna factura emitida: corre antes los pasos de emision.');
        }

        $evento = $this->contingencia->derivar($factura);
        $paquete = $this->contingencia->recuperar($evento);

        if ($paquete === null) {
            throw new SiatException('No se pudo armar el paquete de contingencia.');
        }

        EnviarPaqueteContingencia::dispatch($paquete->id);

        return [
            'evento_id' => $evento->id,
            'paquete_id' => $paquete->id,
            'facturas_en_paquete' => $paquete->cantidad_facturas,
        ];
    }

    /**
     * Ultimo paso: comprueba que todos los anteriores esten en EXITOSO.
     *
     * No cambia el estado de la empresa a proposito. Quien aprueba el piloto es
     * el SIN; el panel ofrece el boton para reflejarlo cuando eso ya paso.
     */
    private function verificarPilotoCompleto(Empresa $empresa, CasoPrueba $caso): array
    {
        $anteriores = CasoPrueba::where('fase', $caso->fase)
            ->where('orden', '<', $caso->orden)
            ->orderBy('orden')
            ->get();

        $ultimas = EjecucionPrueba::where('empresa_id', $empresa->id)
            ->get()
            ->groupBy('caso_id')
            ->map(fn ($grupo) => $grupo->sortByDesc('ejecutado_en')->first());

        $pendientes = $anteriores
            ->filter(fn (CasoPrueba $previo): bool => ($ultimas[$previo->id] ?? null)?->estado !== EjecucionPrueba::ESTADO_EXITOSO)
            ->map(fn (CasoPrueba $previo): string => "{$previo->orden}. {$previo->nombre}")
            ->values()
            ->all();

        if ($pendientes !== []) {
            throw new SiatException('Faltan pasos por superar: '.implode(' · ', $pendientes));
        }

        return [
            'listo_para_aprobar' => true,
            'mensaje' => 'Todos los pasos pasaron. Marca PILOTO_APROBADO desde el panel cuando el SIN lo confirme.',
        ];
    }

    /**
     * Lee el payload_ejemplo del caso o corta con un mensaje que dice que falta.
     *
     * @return array<string, mixed>
     */
    private function payloadDe(CasoPrueba $caso, string $queEs): array
    {
        $payload = $caso->payload_ejemplo;

        if (! is_array($payload) || $payload === []) {
            throw new SiatException(
                "El caso '{$caso->nombre}' necesita {$queEs} en su payload_ejemplo. ".
                'Cargalo con los datos de la especificacion que el SIN genero para este contribuyente.',
            );
        }

        return $payload;
    }

    /**
     * CUIS vigente de la empresa. Casi todo el piloto lo necesita, asi que se
     * corta con un mensaje que dice que paso correr primero.
     */
    private function cuisVigente(Empresa $empresa): string
    {
        $cuis = $this->primerPuntoVenta($empresa)->cuisVigente();

        if ($cuis === null) {
            throw new SiatException('No hay CUIS vigente: corre antes el paso "Solicitar CUIS".');
        }

        return $cuis->codigo;
    }

    /**
     * Registra el punto de venta en el SIAT, UNA SOLA VEZ.
     *
     * El SIN no tiene "registrar si no existe": cada llamada a
     * registroPuntoVenta crea uno nuevo con un codigo nuevo, y un punto de
     * venta no se borra, solo se cierra —y cerrado no se reabre—. Reintentar
     * este paso llenaba la cuenta del contribuyente de duplicados.
     *
     * Ademas se guarda el codigo que devuelve el SIN: el correlativo local
     * arrancaba en 0 y el SIN asigna el suyo, asi que sin esto se emitia con un
     * codigo de punto de venta que el SIN no reconoce.
     *
     * @return array<string, mixed>
     */
    private function registrarPuntoVenta(Empresa $empresa): array
    {
        $puntoVenta = $this->primerPuntoVenta($empresa);

        if ($puntoVenta->estaRegistradoEnSiat()) {
            return [
                'ya_registrado' => true,
                'codigo_punto_venta' => $puntoVenta->codigo_punto_venta,
                'registrado_en' => $puntoVenta->registrado_en_siat->toDateTimeString(),
                'detalle' => 'El punto de venta ya estaba registrado en el SIAT. No se vuelve a registrar: el SIN crearia otro distinto.',
            ];
        }

        $respuesta = RespuestaSiat::desde(
            $this->fabrica->operaciones($empresa)
                ->registrarPuntoVenta($puntoVenta, $this->cuisVigente($empresa)),
            'RespuestaRegistroPuntoVenta',
        );

        if (! $respuesta->aceptada) {
            throw new SiatException("El SIN rechazo el registro del punto de venta: {$respuesta->motivo()}");
        }

        $codigo = data_get($respuesta->crudo, 'codigoPuntoVenta');

        $puntoVenta->update([
            'codigo_punto_venta' => (int) $codigo,
            'registrado_en_siat' => now(),
        ]);

        return [
            'codigo_punto_venta' => (int) $codigo,
            'detalle' => 'Registrado en el SIAT. El codigo local se actualizo al que asigno el SIN.',
        ];
    }

    private function primerPuntoVenta(Empresa $empresa): PuntoVenta
    {
        $puntoVenta = PuntoVenta::query()
            ->whereHas('sucursal', fn ($q) => $q->where('empresa_id', $empresa->id))
            ->where('activo', true)
            ->orderBy('id')
            ->first();

        if ($puntoVenta === null) {
            throw new SiatException('La empresa no tiene ningun punto de venta activo.');
        }

        return $puntoVenta;
    }

    /**
     * Convierte la respuesta SOAP (objeto) en un arreglo serializable a JSON.
     */
    private function normalizar(mixed $respuesta): array
    {
        return json_decode(json_encode($respuesta) ?: '{}', true) ?? [];
    }
}
