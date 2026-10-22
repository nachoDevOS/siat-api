<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\SiatException;
use App\Http\Controllers\Controller;
use App\Models\PuntoVenta;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\RespuestaSiat;
use App\Services\Siat\SiatClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Consulta de los eventos significativos que el SIN tiene registrados para un
 * punto de venta (consultaEventoSignificativo).
 *
 * Es solo lectura: sirve para ver que eventos quedaron de verdad del lado del
 * SIN (por ejemplo, si un motivo lo rechazo), sin ir a SoapUI. Se consulta
 * siempre al SIN y no se guarda nada.
 */
class EventoSignificativoController extends Controller
{
    /**
     * Codigo con el que el SIN responde que ese dia no hay eventos. No es un
     * error: la lista simplemente sale vacia.
     */
    private const SIN_EVENTOS = 957;

    public function __construct(private readonly FabricaServicios $fabrica) {}

    public function index(Request $request, PuntoVenta $puntoVenta): View
    {
        $puntoVenta->load('sucursal.empresa');
        $empresa = $puntoVenta->sucursal->empresa;
        // V2 solo existe donde hay rutas /v2 (el piloto). Las dos versiones
        // comparten la base de eventos del SIN: cambia solo la ruta.
        $versiones = filled(config("siat.urls_v2.{$empresa->codigo_ambiente}"))
            ? [SiatClient::VERSION_V3, SiatClient::VERSION_V2]
            : [SiatClient::VERSION_V3];

        $datos = $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'version' => ['nullable', Rule::in($versiones)],
        ]);

        $vista = [
            'puntoVenta' => $puntoVenta,
            'empresa' => $empresa,
            'versiones' => $versiones,
            'fecha' => $datos['fecha'] ?? now()->toDateString(),
            'version' => $datos['version'] ?? SiatClient::VERSION_V3,
            'consultado' => false,
            'eventos' => [],
            'error' => null,
        ];

        // Sin fecha en la URL solo se muestra el formulario: el SIN se
        // consulta cuando el operador lo pide.
        if (! isset($datos['fecha'])) {
            return view('admin.puntos-venta.eventos', $vista);
        }

        try {
            $vista['eventos'] = $this->consultar($puntoVenta, Carbon::parse($vista['fecha']), $vista['version']);
        } catch (SiatException $e) {
            $vista['error'] = $e->getMessage();
        }

        $vista['consultado'] = true;

        return view('admin.puntos-venta.eventos', $vista);
    }

    /**
     * @return list<array{codigoEvento: int, codigoRecepcion: string, descripcion: string, inicio: ?Carbon, fin: ?Carbon}>
     *
     * @throws SiatException si falta CUIS/CUFD vigente o el SIN rechaza la consulta.
     */
    private function consultar(PuntoVenta $puntoVenta, Carbon $fecha, string $version): array
    {
        $cufd = $puntoVenta->cufdVigente();
        // El CUIS con el que se emitio ese CUFD, no el mas nuevo.
        $cuis = $cufd === null ? null : $puntoVenta->cuisDe($cufd);

        if ($cufd === null || $cuis === null) {
            throw new SiatException('El punto de venta necesita CUIS y CUFD vigentes: el SIN responde 914 con un CUFD vencido.');
        }

        $respuesta = RespuestaSiat::desde(
            $this->fabrica->operaciones($puntoVenta->sucursal->empresa, $version)->consultarEventos(
                (int) $puntoVenta->sucursal->codigo_sucursal,
                (int) $puntoVenta->codigo_punto_venta,
                $cuis->codigo,
                $cufd->codigo,
                $fecha,
            ),
            'RespuestaListaEventos',
        );

        if (! $respuesta->aceptada) {
            if (collect($respuesta->mensajes)->contains(fn (array $m): bool => (int) $m['codigo'] === self::SIN_EVENTOS)) {
                return [];
            }

            throw new SiatException($respuesta->motivo());
        }

        // Un solo evento llega como objeto suelto, varios como lista.
        $lista = data_get($respuesta->crudo, 'listaCodigos', []);
        $lista = is_array($lista) ? $lista : [$lista];

        return collect($lista)
            ->map(fn (mixed $evento): array => [
                'codigoEvento' => (int) data_get($evento, 'codigoEvento'),
                'codigoRecepcion' => (string) data_get($evento, 'codigoRecepcionEventoSignificativo'),
                'descripcion' => (string) data_get($evento, 'descripcion'),
                'inicio' => filled($inicio = data_get($evento, 'fechaInicio')) ? Carbon::parse($inicio) : null,
                'fin' => filled($fin = data_get($evento, 'fechaFin')) ? Carbon::parse($fin) : null,
            ])
            ->sortBy(fn (array $evento): int => $evento['inicio']?->getTimestamp() ?? 0)
            ->values()
            ->all();
    }
}
