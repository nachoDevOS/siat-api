@extends('layouts.admin')
@section('titulo', $empresa->nombre_comercial)

@section('contenido')
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h1>{{ $empresa->nombre_comercial }}</h1>
        <div>
            <a class="btn gris" href="{{ route('admin.pruebas.show', $empresa) }}">Pruebas piloto</a>
            <a class="btn gris" href="{{ route('admin.empresas.edit', $empresa) }}">Editar</a>
        </div>
    </div>

    <div class="tarjeta">
        <h2>Datos</h2>
        <table>
            <tr><th>Razon social</th><td>{{ $empresa->razon_social }}</td></tr>
            <tr><th>NIT</th><td>{{ $empresa->nit }}</td></tr>
            <tr><th>Ambiente</th><td>{{ $empresa->codigo_ambiente === 1 ? 'Produccion' : 'Piloto' }}</td></tr>
            <tr><th>Estado</th><td><span class="pill">{{ $empresa->estado }}</span></td></tr>
            <tr><th>Token delegado</th><td>{{ filled($empresa->token_delegado) ? 'cargado' : '—' }}</td></tr>
            <tr><th>Webhook</th><td>{{ $empresa->webhook_url ?: '—' }}</td></tr>
        </table>
    </div>

    <div class="tarjeta">
        <h2>Certificado digital (.p12)</h2>
        @php($cert = $empresa->certificados->firstWhere('activo', true))
        <p>Activo: {{ $cert ? ('vence '.optional($cert->vence_el)->format('d/m/Y')) : 'ninguno' }}</p>
        <form method="POST" action="{{ route('admin.empresas.certificados.store', $empresa) }}" enctype="multipart/form-data">
            @csrf
            <div class="campo"><label>Archivo .p12</label><input type="file" name="archivo" required></div>
            <div class="campo"><label>Passphrase</label><input type="password" name="passphrase" required></div>
            <div class="campo"><label>Vence el</label><input type="date" name="vence_el"></div>
            <button class="btn" type="submit">Cargar certificado</button>
        </form>
    </div>

    <div class="tarjeta">
        <h2>Sucursales y puntos de venta</h2>
        @forelse ($empresa->sucursales as $sucursal)
            <div style="border-bottom:1px solid #e5e7eb; padding:8px 0;">
                <strong>Sucursal {{ $sucursal->codigo_sucursal }}</strong> — {{ $sucursal->nombre }}
                @foreach ($sucursal->puntosVenta as $pv)
                    @php($cufd = $pv->cufdVigente())
                    @php($cuis = $pv->cuisVigente())
                    <div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:10px; margin:8px 0;">
                        <div><strong>PV {{ $pv->codigo_punto_venta }}</strong> — {{ $pv->nombre }}
                             (proxima factura: {{ $pv->siguiente_factura }})</div>

                        {{-- Estado de los tres codigos. Sin CUFD vigente no se puede emitir. --}}
                        <div style="font-size:13px; margin:6px 0;">
                            CUIS: <span class="{{ $cuis ? 'ok' : 'no' }}">{{ $cuis ? 'vigente' : 'falta' }}</span> ·
                            CUFD: <span class="{{ $cufd ? 'ok' : 'no' }}">{{ $cufd ? 'vigente' : 'falta' }}</span> ·
                            CAFC: <span class="{{ $pv->cafcs()->where('fecha_vigencia', '>', now())->exists() ? 'ok' : 'no' }}">{{ $pv->cafcs()->where('fecha_vigencia', '>', now())->exists() ? 'reserva' : 'sin reserva' }}</span>
                        </div>

                        {{-- Solicitud al SIAT (uso real; requiere WSDL y token validos). --}}
                        <div style="display:flex; gap:6px; margin-bottom:6px;">
                            <form method="POST" action="{{ route('admin.codigos.cuis', $pv) }}">@csrf<button class="btn gris" type="submit">Solicitar CUIS</button></form>
                            <form method="POST" action="{{ route('admin.codigos.cufd', $pv) }}">@csrf<button class="btn gris" type="submit">Solicitar CUFD</button></form>
                            <form method="POST" action="{{ route('admin.codigos.cafc', $pv) }}">@csrf<button class="btn gris" type="submit">Solicitar CAFC</button></form>
                        </div>

                        {{-- Carga manual: para probar el sistema sin conexion al SIN. --}}
                        <details>
                            <summary style="cursor:pointer; font-size:13px;">Carga manual (pruebas sin SIAT)</summary>
                            <form method="POST" action="{{ route('admin.codigos.cuis.manual', $pv) }}" style="display:flex; gap:6px; align-items:end; margin-top:8px;">
                                @csrf
                                <div class="campo" style="margin:0;"><label>CUIS codigo</label><input name="codigo" required></div>
                                <button class="btn" type="submit">+ CUIS</button>
                            </form>
                            <form method="POST" action="{{ route('admin.codigos.cufd.manual', $pv) }}" style="display:flex; gap:6px; align-items:end; margin-top:8px;">
                                @csrf
                                <div class="campo" style="margin:0;"><label>CUFD codigo</label><input name="codigo" required></div>
                                <div class="campo" style="margin:0;"><label>codigo_control</label><input name="codigo_control" required></div>
                                <button class="btn" type="submit">+ CUFD</button>
                            </form>
                            <form method="POST" action="{{ route('admin.codigos.cafc.manual', $pv) }}" style="display:flex; gap:6px; align-items:end; margin-top:8px;">
                                @csrf
                                <div class="campo" style="margin:0;"><label>CAFC codigo</label><input name="codigo" required></div>
                                <div class="campo" style="margin:0;"><label>cant. facturas</label><input name="cantidad_facturas" type="number" value="1000" required></div>
                                <button class="btn" type="submit">+ CAFC</button>
                            </form>
                        </details>
                    </div>
                @endforeach
                <form method="POST" action="{{ route('admin.sucursales.puntos-venta.store', $sucursal) }}" style="display:flex; gap:8px; align-items:end;">
                    @csrf
                    <div class="campo" style="margin:0;"><label>Codigo PV</label><input name="codigo_punto_venta" type="number" required></div>
                    <div class="campo" style="margin:0;"><label>Nombre</label><input name="nombre" required></div>
                    <div class="campo" style="margin:0;"><label>Tipo</label><input name="tipo_punto_venta" type="number" value="1" required></div>
                    <button class="btn" type="submit">+ PV</button>
                </form>
            </div>
        @empty
            <p>Sin sucursales.</p>
        @endforelse

        <h2 style="margin-top:16px;">Nueva sucursal</h2>
        <form method="POST" action="{{ route('admin.empresas.sucursales.store', $empresa) }}" style="display:flex; gap:8px; align-items:end;">
            @csrf
            <div class="campo" style="margin:0;"><label>Codigo</label><input name="codigo_sucursal" type="number" value="0" required></div>
            <div class="campo" style="margin:0;"><label>Nombre</label><input name="nombre" value="Casa Matriz" required></div>
            <div class="campo" style="margin:0;"><label>Municipio</label><input name="municipio"></div>
            <button class="btn" type="submit">+ Sucursal</button>
        </form>
    </div>

    <form method="POST" action="{{ route('admin.empresas.destroy', $empresa) }}"
          onsubmit="return confirm('Eliminar esta empresa y todos sus datos?')">
        @csrf
        @method('DELETE')
        <button class="btn rojo" type="submit">Eliminar empresa</button>
    </form>
@endsection
