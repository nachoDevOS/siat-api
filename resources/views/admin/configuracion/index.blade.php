@extends('layouts.admin')
@section('titulo', 'Configuracion')

@section('contenido')
    <h1>Configuracion del proveedor</h1>
    <p style="color:var(--suave); font-size:14px; margin-top:-6px;">
        Solo lectura. Estos valores salen del <code>.env</code> y valen para todos los clientes
        a la vez. Lo que cambia por cliente (NIT, token, certificado) se edita en su ficha.
    </p>

    @if ($faltantes !== [])
        <div class="clave">
            Falta configurar en el <code>.env</code>: {{ implode(', ', $faltantes) }}.
            Sin esos datos el alta de un cliente no precarga nada.
        </div>
    @endif

    {{-- ---- Identidad ante el SIN ---- --}}
    <div class="tarjeta">
        <h2>Identidad ante el SIN</h2>
        <p style="font-size:13px; color:var(--suave); margin-top:0;">
            De la solicitud de autorizacion de sistemas informaticos de facturacion (R-1359).
        </p>
        <table>
            <tr>
                <th>NIT del proveedor</th>
                <td>{{ $proveedor['nit'] ?: '—' }}</td>
            </tr>
            <tr>
                <th>Razon social</th>
                <td>{{ $proveedor['razon_social'] ?: '—' }}</td>
            </tr>
            <tr>
                <th>Nombre del sistema</th>
                <td>{{ $proveedor['nombre_sistema'] ?: '—' }}</td>
            </tr>
            <tr>
                <th>Codigo de sistema</th>
                <td>
                    <code>{{ $proveedor['codigo_sistema'] ?: '—' }}</code>
                    <div style="font-size:12px; color:var(--suave);">
                        Se precarga al dar de alta un cliente y se puede pisar en su ficha.
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- ---- Ambientes ---- --}}
    <div class="tarjeta">
        <h2>Ambientes del SIAT</h2>
        <table>
            <thead><tr><th>Codigo</th><th>Ambiente</th><th>URL base</th></tr></thead>
            <tbody>
                @foreach ($urls as $codigo => $url)
                    <tr>
                        <td>{{ $codigo }}</td>
                        <td>
                            <x-badge :color="$codigo === 1 ? 'verde' : 'azul'"
                                     :texto="$codigo === 1 ? 'Produccion' : 'Piloto'" />
                        </td>
                        <td><code style="font-size:12px;">{{ $url }}</code></td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h2 style="margin-top:18px;">Servicios SOAP</h2>
        <table>
            <thead><tr><th>Clave interna</th><th>WSDL</th></tr></thead>
            <tbody>
                @foreach ($servicios as $clave => $servicio)
                    <tr>
                        <td>{{ $clave }}</td>
                        <td><code style="font-size:12px;">{{ $servicio }}?wsdl</code></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- ---- Transporte ---- --}}
    <div class="tarjeta">
        <h2>Transporte y limites</h2>
        <table>
            @foreach ($transporte as $etiqueta => $valor)
                <tr><th>{{ $etiqueta }}</th><td>{{ $valor }}</td></tr>
            @endforeach
        </table>
    </div>
@endsection
