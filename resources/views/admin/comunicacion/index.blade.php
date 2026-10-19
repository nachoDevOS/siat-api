@extends('layouts.admin')
@section('titulo', 'Comunicacion SIAT')

@php
    use App\Services\Panel\LecturaLogSiat;

    $visual = LecturaLogSiat::VISUAL;
    $tasa = $totales['total'] > 0 ? round($totales[LecturaLogSiat::ACEPTADA] / $totales['total'] * 100, 1) : null;
    $maximoHora = max(1, $porHora->max('total'));
@endphp

@push('cabecera')
    @include('admin.comunicacion._estilos')
@endpush

@section('contenido')
    <div class="cs-cabeza">
        <div>
            <h1 style="margin:0 0 4px;">Comunicacion con el SIAT</h1>
            <p class="cs-sub">Cada llamada SOAP de tu API al SIN: que se mando, que respondio y por que se rechazo.</p>
        </div>
        <div class="cs-flujo" aria-hidden="true">
            <span class="cs-nodo">Tu API</span>
            <span class="cs-cable"><i></i></span>
            <span class="cs-nodo cs-nodo-sin">SIAT</span>
        </div>
    </div>

    {{-- Lo primero que se busca al entrar: el ultimo problema y su motivo. --}}
    @if ($ultimoProblema)
        @php($problema = $ultimoProblema->log)
        <a href="{{ route('admin.comunicacion.show', $problema) }}"
           class="cs-alerta cs-alerta-{{ $visual[$ultimoProblema->resultado()]['color'] }}">
            <span class="cs-alerta-icono">{{ $ultimoProblema->resultado() === LecturaLogSiat::SIN_RESPUESTA ? '⚡' : '!' }}</span>
            <span class="cs-alerta-cuerpo">
                <strong>Ultimo problema · {{ $problema->operacion }}</strong>
                <span>{{ \Illuminate\Support\Str::limit($ultimoProblema->resumen() ?: 'Sin detalle', 160) }}</span>
                <small>{{ $problema->empresa?->nombre_comercial ?? 'Sin empresa' }} · {{ $problema->created_at?->diffForHumans() }}</small>
            </span>
            <span class="cs-alerta-ir">Analizar →</span>
        </a>
    @endif

    {{-- ---- Indicadores de las ultimas 24 horas ---- --}}
    <div class="cs-kpis">
        <div class="cs-kpi">
            <span class="cs-kpi-rotulo">Llamadas · 24 h</span>
            <span class="cs-kpi-valor">{{ number_format($totales['total']) }}</span>
            <span class="cs-kpi-pie">{{ $porOperacion->count() }} operaciones distintas</span>
        </div>
        <div class="cs-kpi cs-kpi-verde">
            <span class="cs-kpi-rotulo">Aceptadas por el SIN</span>
            <span class="cs-kpi-valor">{{ $tasa === null ? '—' : $tasa.'%' }}</span>
            <span class="cs-kpi-pie">{{ number_format($totales[LecturaLogSiat::ACEPTADA]) }} {{ $totales[LecturaLogSiat::ACEPTADA] === 1 ? 'llamada' : 'llamadas' }}</span>
            @if ($tasa !== null)
                <span class="cs-anillo" style="--p: {{ $tasa }}"></span>
            @endif
        </div>
        <div class="cs-kpi cs-kpi-ambar">
            <span class="cs-kpi-rotulo">Rechazadas por el SIN</span>
            <span class="cs-kpi-valor">{{ number_format($totales[LecturaLogSiat::RECHAZADA]) }}</span>
            <span class="cs-kpi-pie">respondio transaccion = false</span>
        </div>
        <div class="cs-kpi cs-kpi-rojo">
            <span class="cs-kpi-rotulo">Sin respuesta</span>
            <span class="cs-kpi-valor">{{ number_format($totales[LecturaLogSiat::SIN_RESPUESTA]) }}</span>
            <span class="cs-kpi-pie">timeout o SIAT caido</span>
        </div>
        <div class="cs-kpi">
            <span class="cs-kpi-rotulo">Tiempo de respuesta</span>
            <span class="cs-kpi-valor">{{ number_format($totales['promedio_ms']) }}<small> ms</small></span>
            <span class="cs-kpi-pie">el 95% en {{ number_format($totales['p95_ms']) }} ms o menos</span>
        </div>
    </div>

    <div class="cs-grilla">
        {{-- ---- Actividad por hora ---- --}}
        <div class="tarjeta">
            <div class="cs-titulo">
                <h2>Actividad · ultimas 24 horas</h2>
                <span class="cs-leyenda">
                    <i class="l-verde"></i> aceptadas <i class="l-ambar"></i> rechazadas <i class="l-rojo"></i> sin respuesta
                </span>
            </div>
            <div class="cs-barras">
                @foreach ($porHora as $i => $hora)
                    <div class="cs-columna"
                         title="{{ $hora['hora']->format('H:00') }} · {{ $hora['total'] }} llamadas: {{ $hora[LecturaLogSiat::ACEPTADA] }} aceptadas, {{ $hora[LecturaLogSiat::RECHAZADA] }} rechazadas, {{ $hora[LecturaLogSiat::SIN_RESPUESTA] }} sin respuesta">
                        <div class="cs-pila" style="height: {{ $hora['total'] / $maximoHora * 100 }}%">
                            @foreach ([LecturaLogSiat::SIN_RESPUESTA => 'rojo', LecturaLogSiat::RECHAZADA => 'ambar', LecturaLogSiat::ACEPTADA => 'verde'] as $clave => $color)
                                @if ($hora[$clave] > 0)
                                    <span class="b-{{ $color }}" style="flex: {{ $hora[$clave] }}"></span>
                                @endif
                            @endforeach
                        </div>
                        <span class="cs-hora">{{ $i % 3 === 0 ? $hora['hora']->format('H') : '' }}</span>
                    </div>
                @endforeach
            </div>
            @if ($totales['total'] === 0)
                <p class="cs-vacio">Sin llamadas al SIAT en las ultimas 24 horas.</p>
            @endif
        </div>

        {{-- ---- Salud por operacion ---- --}}
        <div class="tarjeta">
            <h2>Salud por operacion · 24 h</h2>
            @forelse ($porOperacion as $op)
                <a class="cs-op" href="{{ route('admin.comunicacion.index', ['operacion' => $op['operacion']]) }}">
                    <span class="cs-op-nombre">{{ $op['operacion'] }}</span>
                    <span class="cs-op-datos">{{ number_format($op['total']) }} · {{ number_format($op['promedio_ms']) }} ms</span>
                    <span class="cs-op-barra">
                        @foreach ([LecturaLogSiat::ACEPTADA => 'verde', LecturaLogSiat::RECHAZADA => 'ambar', LecturaLogSiat::SIN_RESPUESTA => 'rojo'] as $clave => $color)
                            @if ($op[$clave] > 0)
                                <span class="b-{{ $color }}" style="flex: {{ $op[$clave] }}" title="{{ $op[$clave] }} {{ mb_strtolower($visual[$clave]['texto']) }}"></span>
                            @endif
                        @endforeach
                    </span>
                </a>
            @empty
                <p class="cs-vacio">Todavia no hay datos.</p>
            @endforelse
        </div>
    </div>

    {{-- ---- Historial filtrable ---- --}}
    <div class="tarjeta">
        <div class="cs-titulo">
            <h2>Historial de llamadas</h2>
            <span class="cs-sub">{{ number_format($llamadas->total()) }} resultados</span>
        </div>

        <form method="GET" action="{{ route('admin.comunicacion.index') }}" class="filtros" style="margin-bottom:14px;">
            <div class="campo">
                <label for="resultado">Resultado</label>
                <select id="resultado" name="resultado">
                    <option value="">Todos</option>
                    @foreach ($visual as $clave => $v)
                        <option value="{{ $clave }}" @selected(($filtros['resultado'] ?? null) === $clave)>{{ $v['texto'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="campo">
                <label for="operacion">Operacion</label>
                <select id="operacion" name="operacion">
                    <option value="">Todas</option>
                    @foreach ($operaciones as $operacion)
                        <option value="{{ $operacion }}" @selected(($filtros['operacion'] ?? null) === $operacion)>{{ $operacion }}</option>
                    @endforeach
                </select>
            </div>
            <div class="campo">
                <label for="empresa">Empresa</label>
                <select id="empresa" name="empresa">
                    <option value="">Todas</option>
                    @foreach ($empresas as $empresa)
                        <option value="{{ $empresa->id }}" @selected((int) ($filtros['empresa'] ?? 0) === $empresa->id)>{{ $empresa->nombre_comercial }}</option>
                    @endforeach
                </select>
            </div>
            <div class="campo"><label for="desde">Desde</label><input type="date" id="desde" name="desde" value="{{ $filtros['desde'] ?? '' }}"></div>
            <div class="campo"><label for="hasta">Hasta</label><input type="date" id="hasta" name="hasta" value="{{ $filtros['hasta'] ?? '' }}"></div>
            <div class="campo"><label for="buscar">Buscar en el XML</label><input id="buscar" name="buscar" value="{{ $filtros['buscar'] ?? '' }}" placeholder="CUFD, CUIS, codigo de recepcion…"></div>
            <button class="btn" type="submit">Filtrar</button>
            @if (array_filter($filtros))
                <a class="btn gris" href="{{ route('admin.comunicacion.index') }}">Limpiar</a>
            @endif
        </form>

        <div class="cs-tabla">
            <table>
                <thead>
                    <tr><th>Cuando</th><th>Operacion</th><th>Empresa</th><th>Resultado</th><th>Que dijo el SIN</th><th style="text-align:right;">ms</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($llamadas as $llamada)
                        @php($lectura = new LecturaLogSiat($llamada))
                        @php($resultado = $lectura->resultado())
                        <tr class="cs-fila" onclick="location.href='{{ route('admin.comunicacion.show', $llamada) }}'">
                            <td class="cs-cuando">
                                {{ $llamada->created_at?->format('d/m H:i:s') }}
                                <small>{{ $llamada->created_at?->diffForHumans() }}</small>
                            </td>
                            <td>
                                <span class="cs-mono">{{ $llamada->operacion }}</span>
                                <small class="cs-servicio">{{ $llamada->servicio }}</small>
                            </td>
                            <td class="cs-empresa" title="{{ $llamada->empresa?->nombre_comercial }}">{{ $llamada->empresa?->nombre_comercial ?? '—' }}</td>
                            <td><x-badge :color="$visual[$resultado]['color']" :texto="$visual[$resultado]['texto']" /></td>
                            <td class="cs-dijo">{{ \Illuminate\Support\Str::limit($lectura->resumen(), 110) ?: '—' }}</td>
                            <td class="cs-ms {{ $llamada->duracion_ms >= 10000 ? 'lento' : ($llamada->duracion_ms >= 5000 ? 'medio' : '') }}">{{ number_format((int) $llamada->duracion_ms) }}</td>
                            <td><a href="{{ route('admin.comunicacion.show', $llamada) }}" class="cs-ver" aria-label="Ver llamada {{ $llamada->id }}">→</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="cs-vacio">No hay llamadas con esos filtros.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $llamadas->links() }}</div>
    </div>
@endsection
