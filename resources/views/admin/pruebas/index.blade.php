@extends('layouts.admin')
@section('titulo', 'Pruebas piloto')

@section('contenido')
    <h1>Pruebas piloto — {{ $empresa->nombre_comercial }}</h1>

    <div class="tarjeta">
        <h2>Requisitos previos (portal del SIN)</h2>
        <ul>
            <li>Token delegado cargado: <span class="{{ $requisitos['token'] ? 'ok' : 'no' }}">{{ $requisitos['token'] ? 'si' : 'no' }}</span></li>
            <li>Certificado .p12 activo: <span class="{{ $requisitos['certificado'] ? 'ok' : 'no' }}">{{ $requisitos['certificado'] ? 'si' : 'no' }}</span></li>
        </ul>

        @php($habilitado = $requisitos['token'] && $requisitos['certificado'])
        <form method="POST" action="{{ route('admin.pruebas.ejecutar', $empresa) }}">
            @csrf
            <button class="btn" type="submit" @disabled(! $habilitado)>Iniciar pruebas</button>
            @unless ($habilitado)
                <span class="error">Complete token y certificado antes de iniciar.</span>
            @endunless
        </form>
    </div>

    <div class="tarjeta">
        <h2>Secuencia</h2>
        <table>
            <thead><tr><th>#</th><th>Caso</th><th>Estado</th><th>ms</th><th>Ejecutado</th></tr></thead>
            <tbody>
                @foreach ($casos as $caso)
                    @php($ej = $ultimas[$caso->id] ?? null)
                    <tr>
                        <td>{{ $caso->orden }}</td>
                        <td>{{ $caso->nombre }}</td>
                        <td>
                            @if ($ej)
                                <span class="{{ $ej->estado === 'EXITOSO' ? 'ok' : 'no' }}">{{ $ej->estado }}</span>
                            @else
                                <span class="pill">pendiente</span>
                            @endif
                        </td>
                        <td>{{ $ej?->duracion_ms }}</td>
                        <td>{{ optional($ej?->ejecutado_en)->format('d/m H:i') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
