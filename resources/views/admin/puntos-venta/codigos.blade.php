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
        <a class="btn gris" href="{{ route('admin.empresas.show', $empresa) }}">Volver a la ficha</a>
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
            <form method="POST" action="{{ route('admin.codigos.cuis', $puntoVenta) }}">@csrf
                <button class="btn gris" type="submit">Solicitar CUIS</button>
            </form>
            <form method="POST" action="{{ route('admin.codigos.cufd', $puntoVenta) }}">@csrf
                <button class="btn gris" type="submit" @disabled($cuisEnUso === null)
                        title="{{ $cuisEnUso === null ? 'Primero hace falta un CUIS vigente' : '' }}">
                    Solicitar CUFD
                </button>
            </form>
        </div>
    </div>

    {{-- ---- CUIS ---- --}}
    <div class="tarjeta">
        <h2>CUIS ({{ $listaCuis->count() }})</h2>
        <p style="font-size:13px; color:var(--suave); margin-top:0;">
            Dura cerca de un ano. Pedirlo de nuevo devuelve el mismo mientras siga vigente.
        </p>

        <div style="overflow-x:auto;">
            <table>
                <tr>
                    <th>Codigo</th><th>Estado</th><th>Vigencia</th><th>CUFD emitidos</th><th>Solicitado</th>
                </tr>
                @forelse ($listaCuis as $unCuis)
                    <tr>
                        <td style="font-family:monospace;">{{ $unCuis->codigo }}</td>
                        <td>
                            {{-- Vigente es UNO solo: el que el sistema usa. Cualquier otro
                                 no lo esta, tenga o no la fecha vencida —el SIN trabaja con
                                 el ultimo emitido y los anteriores no se vuelven a usar—.
                                 Distinguir "reemplazado" de "vencido" era una diferencia
                                 sin consecuencia practica. --}}
                            @if ($cuisEnUso && $unCuis->id === $cuisEnUso->id)
                                <x-badge color="verde" texto="vigente" />
                            @else
                                <x-badge color="gris" texto="no vigente" />
                            @endif
                        </td>
                        <td>{{ $unCuis->fecha_vigencia->format('d/m/Y H:i') }}
                            <span style="color:var(--suave);">({{ $unCuis->fecha_vigencia->diffForHumans() }})</span>
                        </td>
                        <td>{{ $unCuis->cufds_count }}</td>
                        <td>{{ $unCuis->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="color:var(--suave);">Todavia no se solicito ningun CUIS.</td></tr>
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
        </p>

        <div style="overflow-x:auto;">
            <table>
                <tr>
                    <th>Codigo</th><th>Codigo control</th><th>Estado</th><th>Vigencia</th><th>CUIS</th>
                </tr>
                @forelse ($listaCufd as $unCufd)
                    <tr>
                        <td style="font-family:monospace; word-break:break-all; max-width:320px;">{{ $unCufd->codigo }}</td>
                        <td style="font-family:monospace;">{{ $unCufd->codigo_control }}</td>
                        <td>
                            {{-- Solo el ultimo emitido y todavia dentro de fecha queda
                                 vigente: es el unico con el que se puede emitir. Si el
                                 ultimo ya vencio, ninguno aparece vigente, que es
                                 exactamente lo que pasa. --}}
                            @if ($cufdEnUso && $unCufd->id === $cufdEnUso->id)
                                <x-badge color="verde" texto="vigente" />
                            @else
                                <x-badge color="gris" texto="no vigente" />
                            @endif
                        </td>
                        <td>{{ $unCufd->fecha_vigencia->format('d/m/Y H:i') }}
                            <span style="color:var(--suave);">({{ $unCufd->fecha_vigencia->diffForHumans() }})</span>
                        </td>
                        <td style="font-family:monospace;">
                            {{-- Null en los pedidos antes de que se registrara el vinculo. --}}
                            {{ $unCufd->cuis?->codigo ?? '—' }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="color:var(--suave);">Todavia no se solicito ningun CUFD.</td></tr>
                @endforelse
            </table>
        </div>

        {{ $listaCufd->links() }}
    </div>
@endsection
