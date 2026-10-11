@extends('layouts.admin')
@section('titulo', 'Factura '.$factura->numero_factura)

@section('contenido')
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
        <div>
            <h1 style="margin:0 0 6px;">Factura N° {{ $factura->numero_factura }}</h1>
            <x-estado-factura :estado="$factura->estado" />
            <span style="font-size:13px; color:var(--suave);">
                {{ $factura->empresa->nombre_comercial }} · Suc. {{ $factura->puntoVenta->sucursal->codigo_sucursal }} / PV {{ $factura->puntoVenta->codigo_punto_venta }}
            </span>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a class="btn gris" href="{{ route('admin.facturas.pdf', $factura) }}" target="_blank">PDF</a>
            <a class="btn gris" href="{{ route('admin.facturas.xml', $factura) }}">XML firmado</a>
            @if ($factura->estado === App\Models\Factura::ESTADO_PENDIENTE)
                <form method="POST" action="{{ route('admin.facturas.reenviar', $factura) }}" style="margin:0;">
                    @csrf <button class="btn" type="submit">Enviar al SIN ahora</button>
                </form>
            @endif
            @if (in_array($factura->estado, [App\Models\Factura::ESTADO_RECIBIDA, App\Models\Factura::ESTADO_ENVIADA], true))
                <form method="POST" action="{{ route('admin.facturas.verificar', $factura) }}" style="margin:0;">
                    @csrf <button class="btn" type="submit">Verificar estado ahora</button>
                </form>
            @endif
        </div>
    </div>

    @if ($factura->motivo_observacion)
        <div class="aviso-error" style="margin-top:16px;">
            <strong>El SIN observo esta factura:</strong> {{ $factura->motivo_observacion }}
        </div>
    @endif

    <div class="tarjeta" style="margin-top:16px;">
        <h2>Datos</h2>
        <table>
            <tr><th style="width:220px;">CUF</th><td style="font-family:monospace; word-break:break-all;">{{ $factura->cuf }}</td></tr>
            <tr><th>Fecha de emision</th><td>{{ $factura->fecha_emision?->format('d/m/Y H:i:s') }}</td></tr>
            <tr><th>Tipo de emision</th><td>{{ $factura->tipo_emision === App\Models\Factura::EMISION_CONTINGENCIA ? 'Fuera de linea (contingencia)' : 'En linea' }}</td></tr>
            <tr><th>Referencia externa</th><td>{{ $factura->referencia_externa ?? '—' }}</td></tr>
            <tr><th>Comprador</th><td>{{ $factura->comprador_razon_social }} · Doc. {{ $factura->comprador_tipo_documento }}: {{ $factura->comprador_numero_documento }}{{ $factura->comprador_complemento ? '-'.$factura->comprador_complemento : '' }}{{ $factura->codigo_excepcion ? ' · con excepcion de NIT' : '' }}</td></tr>
            <tr><th>Total / sujeto a IVA</th><td>Bs {{ number_format((float) $factura->monto_total, 2) }} / Bs {{ number_format((float) $factura->monto_total_sujeto_iva, 2) }}</td></tr>
            <tr><th>CUFD usado</th><td>{{ $factura->cufd?->codigo ?? '—' }}</td></tr>
            <tr><th>Codigo de recepcion</th><td>{{ $factura->codigo_recepcion ?? '—' }}</td></tr>
            <tr><th>Codigo de estado del SIN</th><td>{{ $factura->codigo_estado_siat ?? '—' }}</td></tr>
            <tr><th>Enviada / validada</th><td>{{ $factura->enviada_en?->format('d/m/Y H:i:s') ?? '—' }} / {{ $factura->validada_en?->format('d/m/Y H:i:s') ?? '—' }}</td></tr>
            @if ($factura->paquete)
                <tr><th>Paquete de contingencia</th><td>#{{ $factura->paquete->id }} · {{ $factura->paquete->estado }}</td></tr>
            @endif
            @if ($factura->anulacion)
                <tr><th>Anulacion</th><td>{{ $factura->anulacion->estado }} · motivo {{ $factura->anulacion->motivo }} · {{ $factura->anulacion->anulada_en?->format('d/m/Y H:i') }}{{ $factura->anulacion->motivo_rechazo ? ' · rechazo: '.$factura->anulacion->motivo_rechazo : '' }}</td></tr>
            @endif
        </table>
    </div>

    <div class="tarjeta">
        <h2>Detalle</h2>
        <table>
            <thead><tr><th>Producto SIN</th><th>Actividad</th><th>Descripcion</th><th>Cantidad</th><th>P. unitario</th><th>Descuento</th><th>Subtotal</th></tr></thead>
            <tbody>
                @foreach ($factura->items as $item)
                    <tr>
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

    <p><a href="{{ route('admin.facturas.index') }}">← Volver a facturas</a></p>
@endsection
