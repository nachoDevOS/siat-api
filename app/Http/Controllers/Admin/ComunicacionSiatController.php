<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\LogSiat;
use App\Services\Panel\ComparadorLineas;
use App\Services\Panel\LecturaLogSiat;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Comunicacion con el SIAT: cada llamada SOAP que hizo el sistema, que se
 * mando, que respondio el SIN y por que se rechazo.
 *
 * Nace de depurar el piloto a mano: descomprimir el <archivo> de logs_siat,
 * leer la respuesta y comparar una factura rechazada con la ultima aceptada.
 * Esta pantalla hace eso con un clic.
 */
class ComunicacionSiatController extends Controller
{
    /** Columnas del listado: sin xml_enviado, que pesa y no se muestra. */
    private const COLUMNAS_LISTADO = ['id', 'empresa_id', 'servicio', 'operacion', 'duracion_ms', 'exitoso', 'mensaje_error', 'xml_recibido', 'created_at'];

    public function index(Request $request): View
    {
        $filtros = $request->validate([
            'empresa' => ['nullable', 'integer'],
            'operacion' => ['nullable', 'string', 'max:100'],
            'resultado' => ['nullable', Rule::in(array_keys(LecturaLogSiat::VISUAL))],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'buscar' => ['nullable', 'string', 'max:100'],
        ]);

        $llamadas = LogSiat::with('empresa:id,nombre_comercial')
            ->select(self::COLUMNAS_LISTADO)
            ->when($filtros['empresa'] ?? null, fn ($q, $id) => $q->where('empresa_id', $id))
            ->when($filtros['operacion'] ?? null, fn ($q, $operacion) => $q->where('operacion', $operacion))
            ->when($filtros['resultado'] ?? null, fn ($q, $resultado) => LecturaLogSiat::filtrarPorResultado($q, $resultado))
            ->when($filtros['desde'] ?? null, fn ($q, $desde) => $q->where('created_at', '>=', Carbon::parse($desde)->startOfDay()))
            ->when($filtros['hasta'] ?? null, fn ($q, $hasta) => $q->where('created_at', '<=', Carbon::parse($hasta)->endOfDay()))
            // CUF, CUFD, codigo de recepcion... Lo que va dentro del <archivo>
            // esta comprimido y no se encuentra asi.
            ->when($filtros['buscar'] ?? null, fn ($q, $texto) => $q->where(fn ($w) => $w
                ->where('xml_enviado', 'like', "%{$texto}%")
                ->orWhere('xml_recibido', 'like', "%{$texto}%")))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.comunicacion.index', [
            'llamadas' => $llamadas,
            'filtros' => $filtros,
            'empresas' => Empresa::orderBy('nombre_comercial')->get(['id', 'nombre_comercial']),
            'operaciones' => LogSiat::query()->distinct()->orderBy('operacion')->pluck('operacion'),
            ...$this->ultimas24Horas(),
            'ultimoProblema' => $this->ultimoProblema(),
        ]);
    }

    public function show(Request $request, LogSiat $log, ComparadorLineas $comparador): View
    {
        $log->load('empresa');
        $lectura = new LecturaLogSiat($log);

        // Contra que se compara: la elegida a mano o, si esta no fue aceptada,
        // la ultima aceptada de la misma operacion y empresa. Es la pregunta
        // de siempre ante un rechazo: "que cambio desde que funcionaba".
        $referencia = $request->integer('comparar') > 0
            ? LogSiat::find($request->integer('comparar'))
            : $this->ultimaAceptadaAntesDe($log, $lectura);

        $comparacion = null;

        if ($referencia !== null && $referencia->isNot($log)) {
            $otra = new LecturaLogSiat($referencia);

            $comparacion = [
                'log' => $referencia,
                'parametros' => $comparador->comparar($this->comoLineas($otra->parametros()), $this->comoLineas($lectura->parametros())),
                'documento' => $comparador->comparar(
                    $this->lineasDocumento($otra),
                    $this->lineasDocumento($lectura),
                ),
            ];
        }

        $cuf = $lectura->campoDelDocumento('cuf');

        return view('admin.comunicacion.show', [
            'log' => $log,
            'lectura' => $lectura,
            'comparacion' => $comparacion,
            'factura' => $cuf === null ? null : Factura::where('cuf', $cuf)->first(),
            'anterior' => LogSiat::where('id', '<', $log->id)->max('id'),
            'siguiente' => LogSiat::where('id', '>', $log->id)->min('id'),
        ]);
    }

    /**
     * Totales, actividad por hora y salud por operacion de las ultimas 24 h.
     *
     * Se cuenta en SQL si el SIN rechazo (una expresion LIKE sobre la
     * respuesta) para no traer a memoria los XML de miles de llamadas.
     *
     * @return array{totales: array<string, int>, porHora: Collection<int, array<string, mixed>>, porOperacion: Collection<int, array<string, mixed>>}
     */
    private function ultimas24Horas(): array
    {
        [$expresion, $valores] = LecturaLogSiat::sqlRechazada();
        $desde = now()->subHours(23)->startOfHour();

        $filas = LogSiat::query()
            ->where('created_at', '>=', $desde)
            ->selectRaw("operacion, created_at, duracion_ms, exitoso, {$expresion} as rechazada", $valores)
            ->toBase()
            ->get()
            ->map(fn (object $f): array => [
                'operacion' => $f->operacion,
                'hora' => Carbon::parse($f->created_at)->startOfHour()->timestamp,
                'ms' => (int) $f->duracion_ms,
                'resultado' => ! $f->exitoso ? LecturaLogSiat::SIN_RESPUESTA
                    : ((int) $f->rechazada === 1 ? LecturaLogSiat::RECHAZADA : LecturaLogSiat::ACEPTADA),
            ]);

        $contar = fn (Collection $grupo): array => [
            'total' => $grupo->count(),
            LecturaLogSiat::ACEPTADA => $grupo->where('resultado', LecturaLogSiat::ACEPTADA)->count(),
            LecturaLogSiat::RECHAZADA => $grupo->where('resultado', LecturaLogSiat::RECHAZADA)->count(),
            LecturaLogSiat::SIN_RESPUESTA => $grupo->where('resultado', LecturaLogSiat::SIN_RESPUESTA)->count(),
        ];

        $porHora = collect(range(0, 23))->map(function (int $i) use ($desde, $filas, $contar): array {
            $hora = $desde->copy()->addHours($i);

            return ['hora' => $hora, ...$contar($filas->where('hora', $hora->timestamp))];
        });

        $porOperacion = $filas->groupBy('operacion')
            ->map(fn (Collection $grupo, string $operacion): array => [
                'operacion' => $operacion,
                ...$contar($grupo),
                'promedio_ms' => (int) round($grupo->avg('ms')),
            ])
            ->sortByDesc('total')
            ->values();

        $ms = $filas->pluck('ms')->sort()->values();

        return [
            'totales' => [
                ...$contar($filas),
                'promedio_ms' => (int) round($ms->avg() ?? 0),
                // El 95% de las llamadas tardo esto o menos: lo que siente el
                // cajero en los peores momentos, que el promedio esconde.
                'p95_ms' => (int) ($ms->get((int) floor(max(0, $ms->count() - 1) * 0.95)) ?? 0),
            ],
            'porHora' => $porHora,
            'porOperacion' => $porOperacion,
        ];
    }

    /**
     * La llamada mas reciente que no fue aceptada, para destacarla arriba.
     */
    private function ultimoProblema(): ?LecturaLogSiat
    {
        $candidatas = collect([LecturaLogSiat::RECHAZADA, LecturaLogSiat::SIN_RESPUESTA])
            ->map(function (string $resultado): ?LogSiat {
                $consulta = LogSiat::with('empresa:id,nombre_comercial')->select(self::COLUMNAS_LISTADO);
                LecturaLogSiat::filtrarPorResultado($consulta, $resultado);

                return $consulta->latest('id')->first();
            })
            ->filter();

        $ultima = $candidatas->sortByDesc('id')->first();

        return $ultima === null ? null : new LecturaLogSiat($ultima);
    }

    private function ultimaAceptadaAntesDe(LogSiat $log, LecturaLogSiat $lectura): ?LogSiat
    {
        if ($lectura->resultado() === LecturaLogSiat::ACEPTADA) {
            return null;
        }

        $consulta = LogSiat::where('operacion', $log->operacion)
            ->where('empresa_id', $log->empresa_id)
            ->where('id', '<', $log->id);

        LecturaLogSiat::filtrarPorResultado($consulta, LecturaLogSiat::ACEPTADA);

        return $consulta->latest('id')->first();
    }

    /**
     * @param  array<string, string>  $campos
     * @return list<string>
     */
    private function comoLineas(array $campos): array
    {
        return collect($campos)->map(fn (string $valor, string $campo) => "{$campo}: {$valor}")->values()->all();
    }

    /**
     * El primer documento indentado, con la firma acortada: sus valores
     * cambian siempre y taparian las diferencias que importan.
     *
     * @return list<string>
     */
    private function lineasDocumento(LecturaLogSiat $lectura): array
    {
        $xml = $lectura->documentos()[0]['xml'] ?? null;

        return $xml === null ? [] : explode("\n", LecturaLogSiat::legible($xml, acortarFirma: true));
    }
}
