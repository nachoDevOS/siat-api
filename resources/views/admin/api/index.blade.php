@extends('layouts.admin')
@section('titulo', 'Administrar API')

@section('contenido')
    <style>
        .met { display: inline-block; padding: 3px 9px; border-radius: 6px; font-size: 11px; font-weight: 700;
               font-family: monospace; color: #fff; }
        .met.GET { background: #059669; } .met.POST { background: #2563eb; }
        .ruta { font-family: monospace; font-size: 13px; color: #0f172a; }
        .baseurl { font-family: monospace; background: #0b1220; color: #93c5fd; padding: 10px 14px;
                   border-radius: 8px; font-size: 13px; word-break: break-all; }
        .amb-prod { background: #dcfce7; color: #166534; }
        .amb-pilo { background: #fef9c3; color: #854d0e; }
    </style>

    <div class="tarjeta">
        <h2>Base de la API</h2>
        <div class="baseurl">{{ $baseUrl }}v1</div>
        <p class="sub" style="color:var(--suave); font-size:13px; margin:10px 0 0;">
            Documentacion publica para los clientes: <a href="{{ route('docs.api') }}" target="_blank" rel="noopener">{{ route('docs.api') }}</a>.
            Toda peticion exige <code>Authorization: Bearer {token}</code>. Los tokens se crean en la
            ficha de cada empresa, por perfil de permisos. La emision de facturas ademas requiere la
            empresa en <strong>Produccion</strong> e idempotencia.
        </p>
    </div>

    {{-- ---- Catalogo de endpoints ---- --}}
    <div class="tarjeta">
        <h2>Endpoints disponibles</h2>
        <table>
            <thead><tr><th style="width:70px;">Metodo</th><th>Ruta</th><th>Descripcion</th><th>Permiso</th></tr></thead>
            <tbody>
                @foreach ($endpoints as [$metodo, $ruta, $desc, $permiso])
                    <tr>
                        <td><span class="met {{ $metodo }}">{{ $metodo }}</span></td>
                        <td class="ruta">{{ $ruta }}</td>
                        <td>{{ $desc }}</td>
                        <td class="ruta">{{ $permiso }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- ---- Acceso por empresa ---- --}}
    <div class="tarjeta">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <h2>Acceso de empresas</h2>
            <a class="btn" href="{{ route('admin.empresas.create') }}">Nueva empresa</a>
        </div>
        <table>
            <thead>
                <tr><th>Empresa</th><th>NIT</th><th>Ambiente</th><th>Estado</th><th>Tokens activos</th><th>Webhook</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($empresas as $empresa)
                    <tr>
                        <td>{{ $empresa->nombre_comercial }}<br><small style="color:var(--suave);">{{ $empresa->sucursales_count }} sucursales</small></td>
                        <td>{{ $empresa->nit }}</td>
                        <td>
                            @if ($empresa->codigo_ambiente === 1)
                                <span class="pill amb-prod">Produccion</span>
                            @else
                                <span class="pill amb-pilo">Piloto</span>
                            @endif
                        </td>
                        <td><x-estado-empresa :estado="$empresa->estado" /></td>
                        <td>
                            @if ($empresa->tokens_activos_count > 0)
                                <span class="ok">{{ $empresa->tokens_activos_count }}</span>
                            @else
                                <span class="no">ninguno</span>
                            @endif
                        </td>
                        <td>{{ $empresa->webhook_url ? '✓' : '—' }}</td>
                        <td><a href="{{ route('admin.empresas.api', $empresa) }}">Acceso API</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="color:var(--suave);">Sin empresas registradas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
