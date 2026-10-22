@extends('layouts.admin')
@section('titulo', "Eventos PV {$puntoVenta->codigo_punto_venta}")

@section('contenido')
    <div style="display:flex; justify-content:space-between; align-items:start; gap:12px; flex-wrap:wrap;">
        <div>
            <h1 style="margin:0 0 6px;">Eventos significativos del PV {{ $puntoVenta->codigo_punto_venta }}</h1>
            <div style="font-size:13px; color:var(--suave);">
                {{ $empresa->nombre_comercial }} · Sucursal {{ $puntoVenta->sucursal->codigo_sucursal }}
                — {{ $puntoVenta->sucursal->nombre }} · {{ $puntoVenta->nombre }}
            </div>
        </div>
        <div style="display:flex; gap:6px; flex-wrap:wrap;">
            <a class="btn gris" href="{{ route('admin.puntos-venta.codigos', $puntoVenta) }}">Codigos</a>
            <a class="btn gris" href="{{ route('admin.empresas.show', $empresa) }}">Volver a la ficha</a>
        </div>
    </div>

    <div class="tarjeta" style="margin-top:16px;">
        <h2>Consultar en el SIN</h2>
        {{-- El SIN busca por la fecha DEL EVENTO (su inicio y fin), no por el
             dia en que se registro: un evento del 20 registrado el 21 sale
             consultando el 20. --}}
        <p style="font-size:13px; color:var(--suave); margin-top:0;">
            Lista lo que el SIN tiene registrado para este punto de venta. Se busca por la
            <strong>fecha del evento</strong> (su inicio y fin), no por el dia en que se registro.
            Usa el CUFD vigente del punto de venta. Solo consulta: no registra nada.
        </p>

        <form method="GET" action="{{ route('admin.puntos-venta.eventos', $puntoVenta) }}" class="filtros">
            <div class="campo">
                <label for="fecha">Fecha del evento</label>
                <input type="date" id="fecha" name="fecha" value="{{ $fecha }}" required>
            </div>
            @if (count($versiones) > 1)
                {{-- Las dos rutas leen la misma base de eventos del SIN. --}}
                <div class="campo">
                    <label for="version">Ruta</label>
                    <select id="version" name="version">
                        @foreach ($versiones as $unaVersion)
                            <option value="{{ $unaVersion }}" @selected($version === $unaVersion)>
                                {{ strtoupper($unaVersion) }} ({{ $unaVersion === 'v2' ? '/v2' : '/sfd' }})
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif
            <button class="btn" type="submit">Consultar</button>
        </form>
    </div>

    @if ($consultado)
        <div class="tarjeta">
            @if ($error)
                <div class="aviso-error">Error del SIAT: {{ $error }}</div>
            @else
                <h2>{{ count($eventos) }} {{ count($eventos) === 1 ? 'evento' : 'eventos' }} del {{ \Illuminate\Support\Carbon::parse($fecha)->format('d/m/Y') }}</h2>

                @php $porMotivo = collect($eventos)->countBy('codigoEvento')->sortKeys(); @endphp
                @if ($porMotivo->isNotEmpty())
                    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px;">
                        @foreach ($porMotivo as $motivo => $cantidad)
                            <x-badge color="gris" :texto="'Motivo '.$motivo.': '.$cantidad" />
                        @endforeach
                    </div>
                @endif

                <div style="overflow-x:auto;">
                    <table>
                        <tr>
                            <th>Motivo</th><th>Descripcion</th><th>Inicio</th><th>Fin</th><th>Codigo de recepcion</th>
                        </tr>
                        @forelse ($eventos as $evento)
                            <tr>
                                <td>{{ $evento['codigoEvento'] }}</td>
                                <td>{{ $evento['descripcion'] }}</td>
                                <td>{{ $evento['inicio']?->format('d/m/Y H:i:s') ?? '—' }}</td>
                                <td>{{ $evento['fin']?->format('d/m/Y H:i:s') ?? '—' }}</td>
                                <td style="font-family:monospace;">{{ $evento['codigoRecepcion'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" style="color:var(--suave);">
                                El SIN no tiene eventos registrados con esa fecha para este punto de venta.
                            </td></tr>
                        @endforelse
                    </table>
                </div>
            @endif
        </div>
    @endif
@endsection
