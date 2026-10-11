@extends('layouts.admin')
@section('titulo', 'Acceso API · '.$empresa->nombre_comercial)

@section('contenido')
    {{-- Todo lo que el sistema de ventas de esta empresa necesita para
         conectarse, en orden: que se le entrega, con que se autentica, como
         recibe los avisos y que esta llamando. --}}
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
        <div>
            <h1 style="margin:0 0 6px;">Acceso API</h1>
            <span style="font-size:13px; color:var(--suave);">
                {{ $empresa->nombre_comercial }} · NIT {{ $empresa->nit }} ·
                {{ $empresa->codigo_ambiente === 1 ? 'Produccion' : 'Piloto' }} ·
            </span>
            <x-estado-empresa :estado="$empresa->estado" />
        </div>
        <a class="btn gris" href="{{ route('admin.empresas.show', $empresa) }}">← Volver a la ficha</a>
    </div>

    {{-- ---- 1. Datos de conexion ---- --}}
    <div class="tarjeta" style="margin-top:16px;">
        <h2>1. Datos de conexion para el cliente</h2>
        <p style="margin-top:0; font-size:13px; color:var(--suave);">
            Entreguele la carpeta <code>docs/integracion/</code> y, por un canal seguro, estos datos, un token
            (bloque 2) y el secreto del webhook (bloque 3).
        </p>
        <table>
            <tr><th style="width:200px;">URL base</th><td><code>{{ $baseUrl }}</code></td></tr>
            <tr>
                <th>Sucursal / punto de venta</th>
                <td>
                    @forelse ($empresa->sucursales as $sucursal)
                        @foreach ($sucursal->puntosVenta as $pv)
                            <div>
                                <code>sucursal: {{ $sucursal->codigo_sucursal }}, punto_venta: {{ $pv->codigo_punto_venta }}</code>
                                · {{ $pv->nombre }}
                                @unless ($pv->existeEnElSiat())
                                    <x-semaforo color="ambar" texto="no registrado en el SIN: todavia no puede facturar" />
                                @endunless
                            </div>
                        @endforeach
                    @empty
                        <x-semaforo color="ambar" texto="sin sucursales: cargalas en la ficha" />
                    @endforelse
                </td>
            </tr>
            <tr>
                <th>Puede emitir</th>
                <td>
                    @if ($empresa->estaEnProduccion())
                        <x-semaforo color="verde" texto="si (empresa en PRODUCCION)" />
                    @else
                        <x-semaforo color="ambar" texto="todavia no: puede consultar, pero emitir exige estado PRODUCCION" />
                    @endif
                </td>
            </tr>
        </table>
    </div>

    {{-- ---- 2. Token ---- --}}
    {{-- Un token por empresa: el sistema de ventas del cliente lo usa para
         todas sus sucursales y puntos de venta. --}}
    <div class="tarjeta">
        <h2>2. Token</h2>
        <p style="margin-top:0; font-size:13px; color:var(--suave);">
            Un solo token por empresa: el sistema de ventas lo usa para todas sus sucursales y puntos de
            venta, como <code>Authorization: Bearer ...</code>. Regenerarlo revoca el anterior al instante.
        </p>

        @php($token = $tokens->first())

        @if ($tokens->count() > 1)
            <div class="aviso-error">
                Esta empresa tiene {{ $tokens->count() }} tokens de antes de la regla de uno por empresa.
                Regenera para dejar uno solo, o revoca los que sobran.
            </div>
        @endif

        @if ($token)
            <table style="margin-bottom:14px;">
                <thead><tr><th>Perfil</th><th>Creado</th><th>Ultimo uso</th><th>Vence</th><th>IPs permitidas</th><th></th></tr></thead>
                <tbody>
                    @foreach ($tokens as $t)
                        @php($perfil = collect($perfiles)->first(fn ($p) => $p['permisos'] === $t->abilities))
                        <tr>
                            <td>{{ $perfil['nombre'] ?? 'Personalizado' }}</td>
                            <td>{{ $t->created_at?->format('d/m/Y H:i') }}</td>
                            <td>{{ $t->last_used_at?->diffForHumans() ?? 'nunca' }}</td>
                            <td>
                                @if ($t->estaVencido())
                                    <x-semaforo color="rojo" texto="vencido" />
                                @else
                                    {{ $t->expires_at?->format('d/m/Y') ?? 'no vence' }}
                                @endif
                            </td>
                            <td>{{ filled($t->ips_permitidas) ? implode(', ', $t->ips_permitidas) : 'cualquiera' }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.empresas.tokens.destroy', [$empresa, $t]) }}" style="margin:0;"
                                      data-titulo="Revocar token" data-boton="Revocar" data-peligro
                                      data-confirmar="El sistema del cliente deja de poder facturar en ese mismo momento.">
                                    @csrf @method('DELETE')
                                    <button class="btn rojo" type="submit">Revocar</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p style="font-size:13px;"><x-semaforo color="ambar" texto="sin token: el sistema del cliente no puede usar la API" /></p>
        @endif

        <form method="POST" action="{{ route('admin.empresas.tokens.store', $empresa) }}"
              style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;"
              @if ($token) data-titulo="Regenerar token" data-boton="Regenerar" data-peligro
              data-confirmar="El token actual deja de funcionar YA: todas las cajas del cliente paran hasta que cargue el nuevo en su sistema." @endif>
            @csrf
            <label style="flex:1; min-width:150px;">Perfil
                <select name="perfil">
                    @foreach ($perfiles as $clave => $p)
                        <option value="{{ $clave }}" @selected($clave === 'administracion')>{{ $p['nombre'] }}</option>
                    @endforeach
                </select>
            </label>
            <label style="flex:1; min-width:130px;">Vence (opcional)
                <input type="date" name="vence_el">
            </label>
            <label style="flex:2; min-width:160px;">IPs permitidas (opcional)
                <input name="ips_permitidas" placeholder="200.87.1.10, 200.87.1.11">
            </label>
            <button class="btn {{ $token ? 'rojo' : '' }}" type="submit">{{ $token ? 'Regenerar token' : 'Generar token' }}</button>
        </form>
    </div>

    {{-- ---- 3. Webhook ---- --}}
    <div class="tarjeta">
        <h2>3. Webhook (avisos al sistema del cliente)</h2>
        <p style="margin-top:0; font-size:13px; color:var(--suave);">
            Le avisamos por POST cuando el SIN valida u observa una factura y cuando confirma o rechaza una
            anulacion. Debe ser <code>https</code> y apuntar a una IP publica.
        </p>
        <form method="POST" action="{{ route('admin.empresas.api.webhook', $empresa) }}"
              style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;">
            @csrf @method('PUT')
            <label style="flex:1; min-width:260px;">URL
                <input name="webhook_url" value="{{ old('webhook_url', $empresa->webhook_url) }}" placeholder="https://sistema-del-cliente.bo/webhooks/siat">
            </label>
            <button class="btn" type="submit">Guardar</button>
        </form>
        @error('webhook_url')
            <div class="error" style="margin-top:6px;">{{ $message }}</div>
        @enderror
        @if ($empresa->webhook_url)
            <table style="margin-top:12px;">
                <tr>
                    <th style="width:200px;">Secreto</th>
                    <td>
                        @if ($empresa->secretoWebhook())
                            <span class="clave">{{ $empresa->secretoWebhook() }}</span>
                            <div style="font-size:12px; color:var(--suave); margin-top:4px;">
                                Con el verifica la cabecera <code>X-Siat-Signature</code>. Es propio de esta empresa.
                            </div>
                        @else
                            <x-semaforo color="ambar" texto="sin SIAT_WEBHOOK_SECRET en el servidor: los avisos viajan sin firmar" />
                        @endif
                    </td>
                </tr>
            </table>
        @endif
    </div>

    {{-- ---- 4. Ultimas llamadas ---- --}}
    <div class="tarjeta">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <h2>4. Ultimas llamadas</h2>
            <a href="{{ route('admin.monitor', ['empresa' => $empresa->id]) }}" style="font-size:13px;">Ver en el monitor →</a>
        </div>
        <table>
            <thead><tr><th>Fecha</th><th>Token</th><th>Metodo</th><th>Ruta</th><th>HTTP</th><th>IP</th></tr></thead>
            <tbody>
                @forelse ($llamadas as $llamada)
                    <tr>
                        <td>{{ $llamada->created_at?->format('d/m H:i:s') }}</td>
                        <td>{{ $tokens->firstWhere('id', $llamada->token_id)?->name ?? ($llamada->token_id ? '#'.$llamada->token_id.' (revocado)' : '—') }}</td>
                        <td>{{ $llamada->metodo }}</td>
                        <td style="font-family:monospace; font-size:12px;">{{ $llamada->ruta }}</td>
                        <td><span class="{{ $llamada->estado_http < 400 ? 'ok' : 'no' }}">{{ $llamada->estado_http }}</span></td>
                        <td>{{ $llamada->ip }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="color:var(--suave);">Todavia no hubo llamadas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
