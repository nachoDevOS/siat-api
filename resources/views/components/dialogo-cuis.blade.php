{{-- Boton "Solicitar CUIS" con un dialogo donde el operador decide:

     1. Pedir / revisar el CUIS: si el actual sigue vigente, el SIN devuelve
        el mismo (980) y no cambia nada. Es lo seguro.
     2. Forzar uno nuevo: cierra las operaciones del sistema en el punto de
        venta, pide un CUIS nuevo y un CUFD atado a el. Puede hacer rechazar
        las facturas que todavia no viajaron, por eso se advierte cuantas hay.

     Sin CUIS vigente solo aparece la primera opcion: no hay nada que cerrar.
     En piloto tampoco aparece la segunda (ver GestorCodigos::forzarCuisNuevo). --}}
@props(['puntoVenta'])

@php
    $cuisActual = $puntoVenta->cuisVigente();
    $pendientes = App\Models\Factura::where('punto_venta_id', $puntoVenta->id)
        ->where('estado', App\Models\Factura::ESTADO_PENDIENTE)
        ->count();
    $id = 'dialogo-cuis-'.$puntoVenta->id;
@endphp

<button class="btn gris" type="button" onclick="document.getElementById('{{ $id }}').showModal()">Solicitar CUIS</button>

<dialog id="{{ $id }}" class="dialogo">
    <h2 style="margin-top:0;">CUIS del punto de venta {{ $puntoVenta->codigo_punto_venta }}</h2>

    @if ($cuisActual)
        <p style="margin-top:0;">
            CUIS actual: <code>{{ $cuisActual->codigo }}</code>, vigente hasta el
            {{ $cuisActual->fecha_vigencia->format('d/m/Y') }}.
        </p>
    @else
        <p style="margin-top:0;">Este punto de venta no tiene un CUIS vigente.</p>
    @endif

    <div class="opcion">
        <strong>{{ $cuisActual ? 'Revisar el CUIS con el SIN' : 'Pedir CUIS' }}</strong>
        <p>
            @if ($cuisActual)
                Mientras el actual siga vigente, el SIN devuelve <strong>el mismo</strong>: no cambia nada.
                Sirve para confirmar que el SIN lo sigue reconociendo.
            @else
                Le pide al SIN un CUIS para este punto de venta y un CUFD atado a el.
            @endif
        </p>
        <form method="POST" action="{{ route('admin.codigos.cuis', $puntoVenta) }}" style="margin:0;">@csrf
            <button class="btn" type="submit">{{ $cuisActual ? 'Revisar CUIS' : 'Pedir CUIS' }}</button>
        </form>
    </div>

    @if ($cuisActual && $puntoVenta->sucursal->empresa->esSandbox())
        <p style="margin-bottom:0;">
            En piloto no se puede forzar un CUIS nuevo: el SIN puede dejar el punto de venta sin CUIS.
        </p>
    @elseif ($cuisActual)
        <div class="opcion opcion-peligro">
            <strong>Forzar un CUIS nuevo</strong>
            <p>
                Cierra las operaciones del sistema en este punto de venta, pide un CUIS <strong>nuevo</strong> y
                un CUFD atado a el. Hacelo sin ventas en curso.
            </p>
            @if ($pendientes > 0)
                <div class="aviso-error" style="margin-bottom:10px;">
                    Hay {{ $pendientes }} factura(s) PENDIENTE(S) de enviar con el CUFD actual: pueden volver
                    rechazadas por el SIN (913).
                </div>
            @endif
            <form method="POST" action="{{ route('admin.codigos.cuis-nuevo', $puntoVenta) }}" style="margin:0;">@csrf
                <button class="btn rojo" type="submit">Forzar CUIS nuevo</button>
            </form>
        </div>
    @endif

    <form method="dialog" style="margin:14px 0 0; text-align:right;">
        <button class="btn gris" type="submit">Cancelar</button>
    </form>
</dialog>
