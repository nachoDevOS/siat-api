<!DOCTYPE html>
{{-- Representacion grafica en ROLLO (80 mm) para impresoras termicas de
     ticket. Mismo contenido que la carta, en una sola columna: el QR lleva
     t=1 (rollo). dompdf: solo CSS basico, sin flex. --}}
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 8pt 10pt; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 7.5pt; color: #000; margin: 0; }
        .c { text-align: center; }
        .d { text-align: right; }
        .b { font-weight: bold; }
        .sep { border-top: 1px dashed #000; margin: 5pt 0; }
        .cuf { word-wrap: break-word; font-size: 6.5pt; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 1pt 0; vertical-align: top; }
        .item td { padding-top: 3pt; }
        .titulo { font-size: 10pt; font-weight: bold; margin-top: 4pt; }
        .pie { font-size: 6.5pt; text-align: center; margin-top: 4pt; }
    </style>
</head>
<body>
    @php
        $empresa = $factura->empresa;
        $sucursal = $factura->puntoVenta->sucursal;
        $subtotalItems = $factura->items->sum(fn ($i) => (float) $i->subtotal);
        $montoPagar = (float) $factura->monto_total - (float) $factura->gift_card;
    @endphp

    <div class="c">
        <div class="titulo">FACTURA</div>
        <div>(Con Derecho a Crédito Fiscal)</div>
        <div class="b" style="margin-top:4pt;">{{ $empresa->razon_social }}</div>
        <div>{{ $sucursal->codigo_sucursal === 0 ? 'CASA MATRIZ' : 'SUCURSAL N° '.$sucursal->codigo_sucursal }}</div>
        <div>No. Punto de Venta {{ $factura->puntoVenta->codigo_punto_venta }}</div>
        <div>{{ $sucursal->direccion }}</div>
        @if ($sucursal->telefono)<div>Tel. {{ $sucursal->telefono }}</div>@endif
        <div>{{ $sucursal->municipio }}</div>
    </div>

    <div class="sep"></div>
    <div class="c">
        <div class="b">NIT</div><div>{{ $empresa->nit }}</div>
        <div class="b">FACTURA N°</div><div>{{ $factura->numero_factura }}</div>
        <div class="b">CÓD. AUTORIZACIÓN</div><div class="cuf">{{ $factura->cuf }}</div>
    </div>

    <div class="sep"></div>
    <table>
        <tr><td class="b">NOMBRE/RAZÓN SOCIAL:</td></tr>
        <tr><td>{{ $factura->comprador_razon_social }}</td></tr>
        <tr><td><span class="b">NIT/CI/CEX:</span> {{ $factura->comprador_numero_documento }}@if ($factura->comprador_complemento)-{{ $factura->comprador_complemento }}@endif</td></tr>
        <tr><td><span class="b">COD. CLIENTE:</span> {{ $factura->comprador_numero_documento }}</td></tr>
        <tr><td><span class="b">FECHA DE EMISIÓN:</span> {{ $factura->fecha_emision?->format('d/m/Y h:i A') }}</td></tr>
    </table>

    <div class="sep"></div>
    <div class="c b">DETALLE</div>
    <table>
        @foreach ($factura->items as $item)
            <tr class="item"><td colspan="2"><span class="b">{{ $item->codigo_interno ?: $item->codigo_producto_sin }}</span> - {{ $item->descripcion }}</td></tr>
            <tr><td colspan="2">Unidad de medida: {{ $unidades[(string) $item->unidad_medida] ?? $item->unidad_medida }}</td></tr>
            <tr>
                <td>{{ number_format((float) $item->cantidad, 2) }} X {{ number_format((float) $item->precio_unitario, 2) }} - {{ number_format((float) $item->descuento, 2) }}</td>
                <td class="d">{{ number_format((float) $item->subtotal, 2) }}</td>
            </tr>
        @endforeach
    </table>

    <div class="sep"></div>
    <table>
        <tr><td>SUBTOTAL Bs</td><td class="d">{{ number_format($subtotalItems, 2) }}</td></tr>
        <tr><td>DESCUENTO Bs</td><td class="d">{{ number_format((float) $factura->descuento_global, 2) }}</td></tr>
        <tr><td>TOTAL Bs</td><td class="d">{{ number_format((float) $factura->monto_total, 2) }}</td></tr>
        <tr><td>MONTO GIFT CARD Bs</td><td class="d">{{ number_format((float) $factura->gift_card, 2) }}</td></tr>
        <tr class="b"><td>MONTO A PAGAR Bs</td><td class="d">{{ number_format($montoPagar, 2) }}</td></tr>
        <tr class="b"><td>IMPORTE BASE CRÉDITO FISCAL Bs</td><td class="d">{{ number_format((float) $factura->monto_total_sujeto_iva, 2) }}</td></tr>
    </table>
    <div style="margin-top:3pt;">Son: {{ $montoLiteral }}</div>

    <div class="sep"></div>
    <div class="pie b">ESTA FACTURA CONTRIBUYE AL DESARROLLO DEL PAÍS, EL USO ILÍCITO SERÁ SANCIONADO PENALMENTE DE ACUERDO A LEY</div>
    @if ($factura->leyenda)
        <div class="pie">{{ $factura->leyenda }}</div>
    @endif
    <div class="pie">“Este documento es la Representación Gráfica de un Documento Fiscal Digital emitido en una modalidad de facturación en línea”</div>

    <div class="c" style="margin-top:6pt;">
        <img src="{{ $qr }}" alt="QR" width="110" height="110">
    </div>
</body>
</html>
