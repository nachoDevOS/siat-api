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
@endsection
