@extends('layouts.admin')
@section('titulo', 'Trabajos')

@section('contenido')
    {{-- Se actualiza sola cada 10 s (salvo en pausa): asi se ve a los
         trabajos pasar de "en cola" a "exitoso" sin recargar. --}}
    @unless ($filtros['pausa'] ?? false)
        @push('cabecera')<meta http-equiv="refresh" content="10">@endpush
    @endunless

    <style>
        .pestanas { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; }
        .pestanas a { padding: 7px 12px; border-radius: 999px; font-size: 13px; text-decoration: none;
                      background: #e2e8f0; color: #334155; }
        .pestanas a.activa { background: #0f172a; color: #fff; }
        .pestanas a.fallidos { background: #fee2e2; color: #991b1b; }
        .pestanas a.fallidos.activa { background: #dc2626; color: #fff; }
        .procesos { display: flex; gap: 18px; flex-wrap: wrap; font-size: 13px; }
        .error-trabajo { font-size: 12px; color: #b91c1c; margin-top: 3px; }
        .error-trabajo summary { cursor: pointer; }
        .error-trabajo pre { white-space: pre-wrap; font-size: 11px; background: #fef2f2; padding: 8px; border-radius: 6px; }
    </style>

    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
        <h1 style="margin:0;">Trabajos</h1>
        <div class="procesos">
            <x-semaforo :color="$procesos['worker']['activo'] ? 'verde' : 'rojo'" :texto="$procesos['worker']['texto']" />
            <x-semaforo :color="$procesos['scheduler']['activo'] ? 'verde' : 'rojo'" :texto="$procesos['scheduler']['texto']" />
        </div>
    </div>

    <p style="font-size:13px; color:var(--suave); margin:6px 0 16px;">
        Todo lo que hace la cola en segundo plano: enviar facturas al SIN, verificarlas, anularlas, avisar por webhook,
        renovar códigos y correr el piloto.
        @if ($filtros['pausa'] ?? false)
            Actualización automática <strong>en pausa</strong> —
            <a href="{{ request()->fullUrlWithQuery(['pausa' => null]) }}">reanudar</a>.
        @else
            Se actualiza cada 10 segundos —
            <a href="{{ request()->fullUrlWithQuery(['pausa' => 1]) }}">pausar</a>.
        @endif
    </p>

    {{-- Pestanas por estado, con contador. --}}
    <nav class="pestanas">
        <a href="{{ request()->fullUrlWithQuery(['estado' => null, 'page' => null]) }}" class="{{ empty($filtros['estado']) ? 'activa' : '' }}">
            Todos {{ $total }}
        </a>
        @foreach (App\Models\Trabajo::ESTADOS as $estado => $visual)
            <a href="{{ request()->fullUrlWithQuery(['estado' => $estado, 'page' => null]) }}"
               class="{{ ($filtros['estado'] ?? null) === $estado ? 'activa' : '' }} {{ $estado === App\Models\Trabajo::FALLIDO ? 'fallidos' : '' }}">
                {{ $visual['etiqueta'] }} {{ $conteos[$estado] ?? 0 }}
            </a>
        @endforeach
    </nav>

    <div class="tarjeta">
        <form method="GET" action="{{ route('admin.trabajos.index') }}" class="filtros">
            <input type="hidden" name="estado" value="{{ $filtros['estado'] ?? '' }}">
            <div class="campo">
                <label for="clase">Tipo</label>
                <select id="clase" name="clase">
                    <option value="">Todos</option>
                    @foreach (App\Models\Trabajo::NOMBRES as $clase => $nombre)
                        <option value="{{ $clase }}" @selected(($filtros['clase'] ?? '') === $clase)>{{ $nombre }}</option>
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
            <label style="display:flex; gap:6px; align-items:center; margin:0;">
                <input type="checkbox" name="piloto" value="1" style="width:auto; min-width:0;" @checked($filtros['piloto'] ?? false)> Mostrar piloto
            </label>
            <button class="btn" type="submit">Filtrar</button>
            {{-- Por defecto se ve el mes actual; "Ver todo" manda las fechas vacias. --}}
            <a class="btn gris" href="{{ route('admin.trabajos.index') }}">Este mes</a>
            <a class="btn gris" href="{{ route('admin.trabajos.index', ['desde' => '', 'hasta' => '']) }}">Ver todo</a>
        </form>
    </div>

    <div class="tarjeta">
        @if (($conteos[App\Models\Trabajo::FALLIDO] ?? 0) > 0)
            <form method="POST" action="{{ route('admin.trabajos.reintentar-todos') }}" style="margin:0 0 12px; text-align:right;"
                  data-titulo="Reintentar todos los fallidos" data-boton="Reintentar todos"
                  data-confirmar="Vuelven a la cola todos los trabajos fallidos que se pueden reintentar.">
                @csrf <button class="btn" type="submit">Reintentar todos los fallidos</button>
            </form>
        @endif

        <table>
            <thead>
                <tr><th>Fecha</th><th>Qué</th><th>Relacionado</th><th>Estado</th><th>Intentos</th><th>Duración</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($trabajos as $trabajo)
                    @php($visual = App\Models\Trabajo::ESTADOS[$trabajo->estado] ?? ['etiqueta' => $trabajo->estado, 'color' => 'gris'])
                    <tr>
                        <td style="white-space:nowrap;">{{ $trabajo->fecha()?->format('d/m H:i:s') }}</td>
                        <td>
                            {{ $trabajo->nombre() }}
                            <div style="font-size:11px; color:var(--suave);">cola {{ $trabajo->cola }}</div>
                        </td>
                        <td>
                            {{ $trabajo->empresa?->nombre_comercial }}
                            @if ($trabajo->relacionado_tipo === 'factura')
                                · <a href="{{ route('admin.facturas.show', $trabajo->relacionado_id) }}">Factura N° {{ $numerosFactura[$trabajo->relacionado_id] ?? '#'.$trabajo->relacionado_id }}</a>
                            @elseif ($trabajo->relacionado_tipo === 'punto_venta')
                                · <a href="{{ route('admin.puntos-venta.codigos', $trabajo->relacionado_id) }}">Punto de venta #{{ $trabajo->relacionado_id }}</a>
                            @elseif ($trabajo->relacionado_tipo)
                                · {{ str_replace('_', ' ', ucfirst($trabajo->relacionado_tipo)) }} #{{ $trabajo->relacionado_id }}
                            @endif
                        </td>
                        <td>
                            <x-badge :color="$visual['color']" :texto="$visual['etiqueta']" />
                            @if ($trabajo->error)
                                <details class="error-trabajo">
                                    <summary>{{ \Illuminate\Support\Str::limit($trabajo->error, 90) }}</summary>
                                    <pre>{{ $trabajo->error }}</pre>
                                </details>
                            @endif
                        </td>
                        <td>{{ $trabajo->intentos }}</td>
                        <td>{{ $trabajo->duracion_ms !== null ? number_format($trabajo->duracion_ms / 1000, 1).' s' : '—' }}</td>
                        <td style="white-space:nowrap;">
                            @if ($trabajo->estado === App\Models\Trabajo::FALLIDO && isset($enFallidos[$trabajo->uuid]))
                                <form method="POST" action="{{ route('admin.trabajos.reintentar', $trabajo) }}" style="display:inline; margin:0;">
                                    @csrf <button class="btn gris" type="submit">Reintentar</button>
                                </form>
                                <form method="POST" action="{{ route('admin.trabajos.descartar', $trabajo) }}" style="display:inline; margin:0;"
                                      data-titulo="Descartar trabajo fallido" data-boton="Descartar" data-peligro
                                      data-confirmar="Se borra este trabajo fallido. No se vuelve a intentar.">
                                    @csrf @method('DELETE') <button class="btn rojo" type="submit">Descartar</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="color:var(--suave);">No hay trabajos con esos filtros.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div style="margin-top:12px;">{{ $trabajos->links() }}</div>
    </div>
@endsection
