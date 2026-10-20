@extends('layouts.admin')
@php($documento = $factura->esNotaCreditoDebito() ? 'Nota credito-debito' : 'Factura')
@section('titulo', $documento.' '.$factura->numero_factura)

@section('contenido')
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
        <div>
            <h1 style="margin:0 0 6px;">{{ $documento }} N° {{ $factura->numero_factura }}</h1>
            <x-estado-factura :estado="$factura->estado" />
            <span style="font-size:13px; color:var(--suave);">
                {{ $factura->empresa->nombre_comercial }} · Suc. {{ $factura->puntoVenta->sucursal->codigo_sucursal }} / PV {{ $factura->puntoVenta->codigo_punto_venta }}
            </span>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            @if (in_array($factura->estado, [App\Models\Factura::ESTADO_VALIDADA, App\Models\Factura::ESTADO_ANULADA], true))
                <a class="btn" href="{{ $urlVerificacion }}" target="_blank" rel="noopener" title="Consultar la factura en el portal del SIN">
                    <x-icono nombre="escudo" />Verificar en SIAT
                </a>
            @endif
            @if ($factura->xml_firmado)
                {{-- Los mismos enlaces publicos que recibe el sistema de ventas. --}}
                <a class="btn borde ticket" href="{{ $factura->urlPublica('rollo') }}" target="_blank" rel="noopener" title="Imprimir en impresora termica de 80 mm">
                    <x-icono nombre="ticket" />Ticket 80 mm
                </a>
                <a class="btn borde pdf" href="{{ $factura->urlPublica('pdf') }}" target="_blank" rel="noopener" title="Representacion grafica en hoja carta">
                    <x-icono nombre="pdf" />PDF carta
                </a>
            @endif
            <a class="btn borde xml" href="{{ route('admin.facturas.xml', $factura) }}" title="Descargar el documento legal firmado">
                <x-icono nombre="codigo" />XML firmado
            </a>
            @if ($factura->estado === App\Models\Factura::ESTADO_PENDIENTE)
                <form method="POST" action="{{ route('admin.facturas.reenviar', $factura) }}" style="margin:0;">
                    @csrf <button class="btn" type="submit"><x-icono nombre="enviar" />Enviar al SIN ahora</button>
                </form>
            @endif
            @if (in_array($factura->estado, [App\Models\Factura::ESTADO_RECIBIDA, App\Models\Factura::ESTADO_ENVIADA], true))
                <form method="POST" action="{{ route('admin.facturas.verificar', $factura) }}" style="margin:0;">
                    @csrf <button class="btn" type="submit"><x-icono nombre="refrescar" />Verificar estado ahora</button>
                </form>
            @endif
            @if ($esAnulable)
                <button class="btn rojo" type="button" onclick="document.getElementById('dialogo-anular').showModal()">
                    <x-icono nombre="anular" />Anular
                </button>
            @endif
            @if ($esRevertible)
                <form method="POST" action="{{ route('admin.facturas.revertir', $factura) }}" style="margin:0;"
                      data-titulo="Revertir anulacion"
                      data-confirmar="La factura vuelve a estar vigente ante el SIN.&#10;&#10;Solo se puede hacer UNA vez: despues de revertir, esta factura ya no se podra anular de nuevo."
                      data-boton="Revertir anulacion">
                    @csrf <button class="btn borde" type="submit"><x-icono nombre="revertir" />Revertir anulacion</button>
                </form>
            @endif
        </div>
    </div>

    @if ($factura->anulacion?->estado === App\Models\FacturaAnulada::ESTADO_PENDIENTE)
        {{-- La anulacion viaja al SIAT en segundo plano: la ficha pregunta cada
             3 s y se recarga sola cuando el SIAT responde (aparece "Revertir"). --}}
        <div class="aviso" id="esperando-siat" style="margin-top:16px;">
            Esperando que el SIAT confirme la anulacion… la pagina se actualiza sola.
        </div>
        <script>
            (function () {
                const url = @json(route('admin.facturas.estado', $factura));
                let intentos = 0;
                const revisar = function () {
                    intentos++;
                    fetch(url, { headers: { 'Accept': 'application/json' } })
                        .then(function (r) { return r.json(); })
                        .then(function (datos) {
                            if (datos.anulacion !== 'PENDIENTE') { location.reload(); return; }
                            // Unos 2 minutos: si no responde, la cola esta parada o el SIAT caido.
                            if (intentos < 40) { setTimeout(revisar, 3000); return; }
                            document.getElementById('esperando-siat').textContent =
                                'El SIAT todavia no confirma la anulacion. Revise que la cola este corriendo; se reintenta sola cada 15 minutos.';
                        })
                        .catch(function () { if (intentos < 40) { setTimeout(revisar, 3000); } });
                };
                setTimeout(revisar, 3000);
            })();
        </script>
    @endif

    @if ($factura->anulacion?->estado === App\Models\FacturaAnulada::ESTADO_REVERTIDA)
        <div class="aviso" style="margin-top:16px;">
            Esta factura ya se anulo y se revirtio una vez. El SIN no permite anularla de nuevo.
        </div>
    @endif

    @if ($esAnulable)
        {{-- El motivo es obligatorio para el SIN: sale de su catalogo. --}}
        <dialog id="dialogo-anular" class="dialogo">
            <form method="POST" action="{{ route('admin.facturas.anular', $factura) }}">
                @csrf
                <h2 style="margin-top:0;">Anular factura N° {{ $factura->numero_factura }}</h2>
                <p class="mensaje">La factura deja de tener validez ante el SIN. Si fue un error se puede revertir, pero <strong>solo una vez</strong>.</p>
                @if ($motivosAnulacion)
                    <div class="campo" style="margin-top:14px;">
                        <label for="motivo">Motivo</label>
                        <select id="motivo" name="motivo" required>
                            @foreach ($motivosAnulacion as $codigo => $descripcion)
                                <option value="{{ $codigo }}">{{ $descripcion }}</option>
                            @endforeach
                        </select>
                    </div>
                @else
                    <div class="aviso-error" style="margin-top:14px;">No hay motivos de anulacion: sincronice los catalogos del SIN primero.</div>
                @endif
                <div class="acciones">
                    <button type="button" class="btn gris" onclick="this.closest('dialog').close()">Cancelar</button>
                    <button type="submit" class="btn rojo" @disabled(! $motivosAnulacion)>Anular factura</button>
                </div>
            </form>
        </dialog>
    @endif

    @if ($factura->motivo_observacion)
        <div class="aviso-error" style="margin-top:16px;">
            <strong>El SIN observo esta factura:</strong> {{ $factura->motivo_observacion }}
        </div>
    @endif

    <div class="tarjeta" style="margin-top:16px;">
        <h2>Datos</h2>
        <table>
            @if ($factura->facturaOrigen)
                <tr><th style="width:220px;">Factura que ajusta</th><td><a href="{{ route('admin.facturas.show', $factura->facturaOrigen) }}">Factura N° {{ $factura->facturaOrigen->numero_factura }}</a> · Bs {{ number_format((float) $factura->facturaOrigen->monto_total, 2) }}</td></tr>
                <tr><th>Devuelto / credito fiscal (13%)</th><td>Bs {{ number_format((float) $factura->subtotal, 2) }} / Bs {{ number_format((float) $factura->monto_efectivo_credito_debito, 2) }}</td></tr>
            @endif
            <tr><th style="width:220px;">CUF</th><td style="font-family:monospace; word-break:break-all;">{{ $factura->cuf }}</td></tr>
            <tr><th>Fecha de emision</th><td>{{ $factura->fecha_emision?->format('d/m/Y H:i:s') }}</td></tr>
            <tr><th>Tipo de emision</th><td>{{ $factura->tipo_emision === App\Models\Factura::EMISION_CONTINGENCIA ? 'Fuera de linea (contingencia)' : 'En linea' }}</td></tr>
            <tr><th>Referencia externa</th><td>{{ $factura->referencia_externa ?? '—' }}</td></tr>
            <tr><th>Comprador</th><td>{{ $factura->comprador_razon_social }} · Doc. {{ $factura->comprador_tipo_documento }}: {{ $factura->comprador_numero_documento }}{{ $factura->comprador_complemento ? '-'.$factura->comprador_complemento : '' }}{{ $factura->codigo_excepcion ? ' · con excepcion de NIT' : '' }}</td></tr>
            <tr><th>Descuento global / gift card</th><td>Bs {{ number_format((float) $factura->descuento_global, 2) }} / Bs {{ number_format((float) $factura->gift_card, 2) }}</td></tr>
            <tr><th>Neto / IVA / a pagar</th><td>Bs {{ number_format((float) $factura->total_precio_neto, 2) }} / Bs {{ number_format((float) $factura->iva, 2) }} / Bs {{ number_format((float) $factura->monto_total, 2) }}</td></tr>
            <tr><th>CUFD usado</th><td>{{ $factura->cufd?->codigo ?? '—' }}</td></tr>
            <tr><th>Codigo de recepcion</th><td>{{ $factura->codigo_recepcion ?? '—' }}</td></tr>
            <tr><th>Codigo de estado del SIN</th><td>{{ $factura->codigo_estado_siat ?? '—' }}</td></tr>
            <tr><th>Enviada / validada</th><td>{{ $factura->enviada_en?->format('d/m/Y H:i:s') ?? '—' }} / {{ $factura->validada_en?->format('d/m/Y H:i:s') ?? '—' }}</td></tr>
            @if ($factura->paquete)
                <tr><th>Paquete de contingencia</th><td>#{{ $factura->paquete->id }} · {{ $factura->paquete->estado }}</td></tr>
            @endif
            @if ($factura->anulacion)
                <tr><th>Anulacion</th><td>{{ $factura->anulacion->estado }}{{ $factura->anulacion->esAdministrativa() ? ' · ADMINISTRATIVA, resolucion '.$factura->anulacion->numero_resolucion : '' }} · motivo {{ $factura->anulacion->motivo }} · {{ $factura->anulacion->anulada_en?->format('d/m/Y H:i') }}{{ $factura->anulacion->motivo_rechazo ? ' · rechazo: '.$factura->anulacion->motivo_rechazo : '' }}</td></tr>
            @endif
        </table>
    </div>

    <div class="tarjeta">
        <h2>Detalle</h2>
        <table>
            <thead><tr>@if ($factura->esNotaCreditoDebito())<th>Item</th>@endif<th>Producto SIN</th><th>Actividad</th><th>Descripcion</th><th>Cantidad</th><th>P. unitario</th><th>Descuento</th><th>Subtotal</th></tr></thead>
            <tbody>
                @foreach ($factura->items as $item)
                    <tr>
                        @if ($factura->esNotaCreditoDebito())
                            <td>{{ $item->esDevuelto() ? 'Devuelto' : 'Original' }}</td>
                        @endif
                        <td>{{ $item->codigo_producto_sin }}</td>
                        <td>{{ $item->codigo_actividad ?? '—' }}</td>
                        <td>{{ $item->descripcion }}</td>
                        <td>{{ number_format((float) $item->cantidad, 2) }}</td>
                        <td>{{ number_format((float) $item->precio_unitario, 2) }}</td>
                        <td>{{ number_format((float) $item->descuento, 2) }}</td>
                        <td>{{ number_format((float) $item->subtotal, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($factura->notas->isNotEmpty())
        <div class="tarjeta">
            <h2>Notas de credito-debito</h2>
            <table>
                <thead><tr><th>Nota N°</th><th>Fecha</th><th>Estado</th><th>Devuelto</th><th>Credito fiscal</th></tr></thead>
                <tbody>
                    @foreach ($factura->notas as $nota)
                        <tr>
                            <td><a href="{{ route('admin.facturas.show', $nota) }}">{{ $nota->numero_factura }}</a></td>
                            <td>{{ $nota->fecha_emision?->format('d/m/Y H:i') }}</td>
                            <td><x-estado-factura :estado="$nota->estado" /></td>
                            <td>Bs {{ number_format((float) $nota->subtotal, 2) }}</td>
                            <td>Bs {{ number_format((float) $nota->monto_efectivo_credito_debito, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <p><a href="{{ route('admin.facturas.index') }}">← Volver a facturas</a></p>
@endsection
