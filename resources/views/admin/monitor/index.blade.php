@extends('layouts.admin')
@section('titulo', 'Monitor')

@section('contenido')
    <h1>Monitor</h1>

    <div class="tarjeta">
        <h2>Facturas por estado</h2>
        <table>
            <tr>@foreach ($porEstado as $estado => $total)<th>{{ $estado }}</th>@endforeach</tr>
            <tr>@foreach ($porEstado as $estado => $total)<td>{{ $total }}</td>@endforeach</tr>
            @if ($porEstado->isEmpty())<tr><td>Sin facturas aun.</td></tr>@endif
        </table>
    </div>

    <div class="tarjeta">
        <h2>Ultimas peticiones SOAP</h2>
        <table>
            <thead><tr><th>Fecha</th><th>Empresa</th><th>Servicio</th><th>Operacion</th><th>ms</th><th>Ok</th></tr></thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td>{{ optional($log->created_at)->format('d/m H:i:s') }}</td>
                        <td>{{ $log->empresa?->nombre_comercial ?? '—' }}</td>
                        <td>{{ $log->servicio }}</td>
                        <td>{{ $log->operacion }}</td>
                        <td>{{ $log->duracion_ms }}</td>
                        <td><span class="{{ $log->exitoso ? 'ok' : 'no' }}">{{ $log->exitoso ? 'si' : 'no' }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="6">Sin peticiones registradas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="tarjeta">
        <h2>Ultimas llamadas a la API</h2>
        <form method="GET" action="{{ route('admin.monitor') }}" class="filtros" style="margin-bottom:12px;">
            <div class="campo">
                <label for="empresa">Empresa</label>
                <select id="empresa" name="empresa">
                    <option value="">Todas</option>
                    @foreach ($empresas as $empresa)
                        <option value="{{ $empresa->id }}" @selected($empresaId === $empresa->id)>{{ $empresa->nombre_comercial }}</option>
                    @endforeach
                </select>
            </div>
            <label style="display:flex; gap:6px; align-items:center; margin:0;">
                <input type="checkbox" name="errores" value="1" style="width:auto; min-width:0;" @checked($soloErrores)> Solo errores
            </label>
            <button class="btn" type="submit">Filtrar</button>
        </form>
        <table>
            <thead><tr><th>Fecha</th><th>Empresa</th><th>Token</th><th>Metodo</th><th>Ruta</th><th>HTTP</th><th>IP</th><th>ms</th></tr></thead>
            <tbody>
                @forelse ($llamadas as $llamada)
                    <tr>
                        <td>{{ $llamada->created_at?->format('d/m H:i:s') }}</td>
                        <td>{{ $llamada->empresa?->nombre_comercial ?? '—' }}</td>
                        <td>{{ $llamada->token_id ? ($tokens[$llamada->token_id] ?? "#{$llamada->token_id} (revocado)") : '—' }}</td>
                        <td>{{ $llamada->metodo }}</td>
                        <td style="font-family:monospace; font-size:12px;">{{ $llamada->ruta }}</td>
                        <td><span class="{{ $llamada->estado_http < 400 ? 'ok' : 'no' }}">{{ $llamada->estado_http }}</span></td>
                        <td>{{ $llamada->ip }}</td>
                        <td>{{ $llamada->duracion_ms }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8">Sin llamadas registradas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
