@extends('layouts.admin')
@section('titulo', 'Solicitudes de contacto')

@section('contenido')
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
        <div>
            <h1 style="margin:0 0 4px;">Solicitudes de contacto</h1>
            <p style="margin:0; color:var(--suave); font-size:14px;">Lo que dejan los interesados en el formulario del sitio público.</p>
        </div>
        <div style="display:flex; gap:8px;">
            <a class="btn {{ $soloPendientes ? '' : 'gris' }}" href="{{ route('admin.solicitudes.index') }}">Pendientes</a>
            <a class="btn {{ $soloPendientes ? 'gris' : '' }}" href="{{ route('admin.solicitudes.index', ['ver' => 'todas']) }}">Todas</a>
        </div>
    </div>

    <div class="tarjeta" style="margin-top:16px;">
        @if ($solicitudes->isEmpty())
            <p style="margin:0; color:var(--suave);">
                {{ $soloPendientes ? 'No hay solicitudes pendientes.' : 'Todavía no llegó ninguna solicitud.' }}
                Las que se envíen desde <a href="{{ route('sitio.inicio') }}#contacto" target="_blank" rel="noopener">el sitio</a> aparecen acá.
            </p>
        @else
            <table>
                <thead><tr><th>Fecha</th><th>Contacto</th><th>Busca</th><th>Mensaje</th><th></th></tr></thead>
                <tbody>
                    @foreach ($solicitudes as $solicitud)
                        <tr>
                            <td style="white-space:nowrap;">{{ $solicitud->created_at?->format('d/m/Y H:i') }}</td>
                            <td>
                                <strong>{{ $solicitud->nombre }}</strong>
                                @if ($solicitud->empresa)<br><span style="color:var(--suave);">{{ $solicitud->empresa }}</span>@endif
                                <br><a href="mailto:{{ $solicitud->correo }}">{{ $solicitud->correo }}</a>
                                @if ($solicitud->telefono)<br>{{ $solicitud->telefono }}@endif
                            </td>
                            <td>{{ $solicitud->etiquetaInteres() }}</td>
                            <td style="max-width:420px; white-space:pre-line;">{{ $solicitud->mensaje }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.solicitudes.atender', $solicitud) }}" style="margin:0;">
                                    @csrf
                                    <button class="btn {{ $solicitud->estaAtendida() ? 'gris' : '' }}" type="submit">
                                        {{ $solicitud->estaAtendida() ? 'Reabrir' : 'Atendida' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            {{ $solicitudes->links() }}
        @endif
    </div>
@endsection
