<?php

namespace App\Services\Pruebas;

use App\Exceptions\SiatException;
use App\Jobs\AnularFacturaEnSiat;
use App\Jobs\EnviarPaqueteContingencia;
use App\Models\CasoPrueba;
use App\Models\Cufd;
use App\Models\Cuis;
use App\Models\EjecucionPrueba;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\FacturaAnulada;
use App\Models\PuntoVenta;
use App\Services\Catalogos\SincronizadorEmpresa;
use App\Services\Catalogos\SincronizadorGlobal;
use App\Services\Contingencia\GestorContingencia;
use App\Services\Factura\EmisorFactura;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\RespuestaSiat;
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
    ) {}

    /**
     * Ejecuta la secuencia completa de una fase para una empresa.
     * Se detiene en el primer caso obligatorio que falle.
     *
     * @return array{ejecutados: int, fallo: ?string}
     */
    public function ejecutarSecuencia(Empresa $empresa, int $fase): array
    {
        $casos = CasoPrueba::where('fase', $fase)->orderBy('orden')->get();
        $ejecutados = 0;

        foreach ($casos as $caso) {
            $ejecucion = $this->ejecutarCaso($empresa, $caso);
            $ejecutados++;

            if ($ejecucion->estado === EjecucionPrueba::ESTADO_FALLIDO && $caso->obligatorio) {
                return ['ejecutados' => $ejecutados, 'fallo' => $caso->nombre];
            }
        }

        return ['ejecutados' => $ejecutados, 'fallo' => null];
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
            $datos = ['error' => $e->getMessage()];
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
            'cufd' => $this->solicitarCufd($empresa),

            // --- Pasos 6 a 9: catalogos --------------------------------------
            'sincronizarGlobales' => $this->catalogosGlobales
                ->sincronizarTodo($empresa, $this->cuisVigente($empresa)),
            'listaActividades' => ['actividades' => $this->catalogosEmpresa
                ->sincronizarActividades($empresa, $this->cuisVigente($empresa))],
            'listaProductos' => ['productos' => $this->catalogosEmpresa
                ->sincronizarProductos($empresa, $this->cuisVigente($empresa))],
            'listaLeyendas' => ['leyendas' => $this->catalogosEmpresa
                ->sincronizarLeyendas($empresa, $this->cuisVigente($empresa))],

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
