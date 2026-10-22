@extends('layouts.admin')
@section('titulo', "Codigos PV {$puntoVenta->codigo_punto_venta}")

@section('contenido')
    <div style="display:flex; justify-content:space-between; align-items:start; gap:12px; flex-wrap:wrap;">
        <div>
            <h1 style="margin:0 0 6px;">Codigos del PV {{ $puntoVenta->codigo_punto_venta }}</h1>
            <div style="font-size:13px; color:var(--suave);">
                {{ $empresa->nombre_comercial }} · Sucursal {{ $puntoVenta->sucursal->codigo_sucursal }}
                — {{ $puntoVenta->sucursal->nombre }} · {{ $puntoVenta->nombre }}
            </div>
        </div>
        <div style="display:flex; gap:6px; flex-wrap:wrap;">
            <a class="btn gris" href="{{ route('admin.puntos-venta.eventos', $puntoVenta) }}">Eventos significativos</a>
            <a class="btn gris" href="{{ route('admin.empresas.show', $empresa) }}">Volver a la ficha</a>
        </div>
    </div>

    {{-- Lo que el sistema usa AHORA. Es la pregunta que se hace el 90% de las
         veces que se entra aca, asi que va arriba y no hay que buscarla en la
         tabla. El codigo de control es el que se pega al final de cada CUF. --}}
    <div class="tarjeta" style="margin-top:16px;">
        <h2>En uso ahora</h2>
        <div class="codigos">
            <div class="fila">
                <span class="etq">CUIS</span>
                @if ($cuisEnUso)
                    <span class="val">{{ $cuisEnUso->codigo }}</span>
                    @if ($compartidoEntreVersiones)
                        <x-badge color="verde" texto="sirve para V2 y V3" />
                    @endif
                    <span style="color:var(--suave);">vence {{ $cuisEnUso->fecha_vigencia->diffForHumans() }}</span>
                @else
                    <span class="error">sin CUIS vigente — no se puede pedir CUFD</span>
                @endif
            </div>
            <div class="fila">
                <span class="etq">CUFD</span>
                @if ($cufdEnUso)
                    <span class="val">{{ $cufdEnUso->codigo }}</span>
                    <span style="color:var(--suave);">vence {{ $cufdEnUso->fecha_vigencia->diffForHumans() }}</span>
                @elseif ($cuisEnUso)
                    {{-- Con CUIS no es un problema: la emision lo pide sola. --}}
                    <span style="color:var(--suave);">sin CUFD — se pide solo al emitir la primera factura</span>
                @else
                    <span class="error">sin CUFD vigente — no se puede emitir</span>
                @endif
            </div>
            @if ($cufdEnUso)
                <div class="fila">
                    <span class="etq">Codigo control</span>
                    <span class="val">{{ $cufdEnUso->codigo_control }}</span>
                </div>
            @endif
        </div>

        <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:12px;">
            <x-dialogo-cuis :punto-venta="$puntoVenta" />
            <form method="POST" action="{{ route('admin.codigos.cufd', $puntoVenta) }}">@csrf
                <button class="btn gris" type="submit" @disabled($cuisEnUso === null)
                        title="{{ $cuisEnUso === null ? 'Primero hace falta un CUIS vigente' : '' }}">
                    Solicitar CUFD
                </button>
            </form>
        </div>

        {{-- Solo en el piloto: que la etapa I de este punto de venta este
             completa es lo primero que pide el portal del SIN. --}}
        @if ($pruebasEtapaUno)
            <div style="margin-top:16px; padding-top:14px; border-top:1px solid var(--borde);">
                <div style="font-size:13px; font-weight:600; margin-bottom:8px;">
                    Piloto · etapa I de este punto de venta
                    <span style="color:var(--suave); font-weight:400;">
                        ({{ collect($pruebasEtapaUno)->where('contada', true)->count() }} de {{ count($pruebasEtapaUno) }} contadas)
                    </span>
                </div>
                @include('admin.puntos-venta._cuis-etapa-uno', ['pruebas' => $pruebasEtapaUno])
            </div>
        @endif
    </div>

    {{-- ---- CUIS ---- --}}
    {{-- Una sola tabla: el SIN tiene UN CUIS vigente por punto de venta para
         las dos versiones (verificado el 19/10/2026, tambien con SoapUI). La
         version es solo por que ruta se pidio, no un CUIS aparte. --}}
    <div class="tarjeta">
        <h2>CUIS ({{ $listaCuis->count() }})</h2>
        <p style="font-size:13px; color:var(--suave); margin-top:0;">
            Dura cerca de un ano. Pedirlo de nuevo devuelve el mismo mientras siga vigente.
            @if ($compartidoEntreVersiones)
                El SIN tiene <strong>un solo CUIS vigente por punto de venta para las dos versiones</strong>:
                pedir uno nuevo en una version cierra el de la otra.
            @endif
        </p>

        <div style="overflow-x:auto;">
            <table>
                <tr>
                    <th>Codigo</th><th>Pedido por</th><th>Estado</th><th>Vigencia / cierre</th><th>CUFD emitidos</th><th>Solicitado</th>
                </tr>
                @forelse ($listaCuis as $unCuis)
                    @php
                        $estado = $unCuis->estadoFrente($cuisEnUso);
                        $visual = \App\Services\Panel\EstadosVisuales::codigo($estado);
                    @endphp
                    <tr @if ($estado === 'vigente') style="background:#f0fdf4;" @endif>
                        <td style="font-family:monospace; font-weight:{{ $estado === 'vigente' ? 700 : 400 }};">{{ $unCuis->codigo }}</td>
                        {{-- Null = guardado antes de registrar la version. --}}
                        <td>{{ $unCuis->version ? strtoupper($unCuis->version) : '—' }}</td>
                        <td><x-badge :color="$visual['color']" :texto="$visual['etiqueta']" /></td>
                        <td>
                            @if ($estado === 'cerrado')
                                cerrado {{ $unCuis->cerrado_en->format('d/m/Y H:i') }}
                                <span style="color:var(--suave);">({{ $unCuis->cerrado_en->diffForHumans() }})</span>
                            @elseif ($estado === 'reemplazado')
                                {{-- Sigue con fecha futura pero ya hay otro mas nuevo:
                                     el SIN trabaja con el ultimo emitido. --}}
                                <span style="color:var(--suave);">reemplazado por uno mas nuevo</span>
                            @else
                                hasta {{ $unCuis->fecha_vigencia->format('d/m/Y H:i') }}
                                <span style="color:var(--suave);">({{ $unCuis->fecha_vigencia->diffForHumans() }})</span>
                            @endif
                        </td>
                        <td>{{ $unCuis->cufds_count }}</td>
                        <td>{{ $unCuis->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="color:var(--suave);">Todavia no se solicito ningun CUIS.</td></tr>
                @endforelse
            </table>
        </div>
    </div>

    {{-- ---- CUFD ---- --}}
    <div class="tarjeta">
        <h2>CUFD ({{ $listaCufd->total() }})</h2>
        <p style="font-size:13px; color:var(--suave); margin-top:0;">
            Dura 24 horas y el SIN emite uno nuevo en cada solicitud. Su codigo de control
            se pega al final del CUF de toda factura emitida mientras estuvo en uso.
            Solo sirve con el CUIS con el que se pidio.
        </p>

        <div style="overflow-x:auto;">
            <table>
                <tr>
                    <th>Codigo</th><th>Pedido por</th><th>Codigo control</th><th>Estado</th><th>Vigencia</th><th>CUIS</th>
                </tr>
                @forelse ($listaCufd as $unCufd)
                    @php
                        $estado = $unCufd->estadoFrente($cufdEnUso);
                        $visual = \App\Services\Panel\EstadosVisuales::codigo($estado);
                    @endphp
                    <tr @if ($estado === 'vigente') style="background:#f0fdf4;" @endif>
                        <td style="font-family:monospace; word-break:break-all; max-width:320px;">{{ $unCufd->codigo }}</td>
                        <td>{{ $unCufd->version ? strtoupper($unCufd->version) : '—' }}</td>
                        <td style="font-family:monospace;">{{ $unCufd->codigo_control }}</td>
                        <td><x-badge :color="$visual['color']" :texto="$visual['etiqueta']" /></td>
                        <td>{{ $unCufd->fecha_vigencia->format('d/m/Y H:i') }}
                            <span style="color:var(--suave);">({{ $unCufd->fecha_vigencia->diffForHumans() }})</span>
                        </td>
                        <td style="font-family:monospace;">
                            {{-- Null en los pedidos antes de que se registrara el vinculo. --}}
                            {{ $unCufd->cuis?->codigo ?? '—' }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="color:var(--suave);">
                        Todavia no se solicito ningun CUFD.
                        @if ($cuisEnUso) Se pide solo al emitir la primera factura, o con "Solicitar CUFD". @endif
                    </td></tr>
                @endforelse
            </table>
        </div>

        {{ $listaCufd->links() }}
    </div>
@endsection
