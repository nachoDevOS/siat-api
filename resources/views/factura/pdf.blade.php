<!DOCTYPE html>
{{-- Representacion grafica de la factura (sector 1, compra-venta, electronica
     en linea). El documento LEGAL es el XML firmado; esto es lo que el
     comprador imprime, asi que lleva todo lo que el SIN espera ver: titulo
     con credito fiscal, datos del emisor y de la sucursal, monto en literal,
     leyenda de la actividad y la de documento fiscal digital, y el QR. --}}
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        /* dompdf solo soporta CSS basico: tablas y bloques, nada de flex. */
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 15px; margin: 0; text-align: center; }
        .sub { text-align: center; font-size: 10px; margin: 2px 0 10px; }
        table { width: 100%; border-collapse: collapse; }
        .datos td { padding: 2px 4px; vertical-align: top; border: none; }
        .detalle { margin-top: 8px; }
        .detalle th, .detalle td { border: 1px solid #999; padding: 4px; text-align: left; }
        .detalle th { background: #eee; }
        .detalle .derecha { text-align: right; }
        .derecha { text-align: right; }
        .totales { width: 50%; margin-left: 50%; margin-top: 6px; }
        .totales th, .totales td { border: 1px solid #999; padding: 3px 4px; }
        .totales th { text-align: left; font-weight: normal; }
        .total-pagar th, .total-pagar td { font-weight: bold; }
        .literal { margin-top: 6px; font-weight: bold; }
        .pie { margin-top: 12px; text-align: center; font-size: 9px; }
        .pie p { margin: 4px 0; }
        .qr { text-align: center; margin-top: 8px; }
    </style>
</head>
<body>
    @php
        $empresa = $factura->empresa;
        $sucursal = $factura->puntoVenta->sucursal;
        $subtotalItems = $factura->items->sum(fn ($i) => (float) $i->subtotal);
        $montoPagar = (float) $factura->monto_total - (float) $factura->gift_card;
    @endphp

    {{-- ---- Emisor ---- --}}
    <table class="datos">
        <tr>
            <td style="width:55%;">
                <strong>{{ $empresa->razon_social }}</strong><br>
                {{ $sucursal->codigo_sucursal === 0 ? 'CASA MATRIZ' : 'SUCURSAL N° '.$sucursal->codigo_sucursal }}<br>
                No. Punto de Venta {{ $factura->puntoVenta->codigo_punto_venta }}<br>
                {{ $sucursal->direccion }}<br>
                @if ($sucursal->telefono) Teléfono: {{ $sucursal->telefono }}<br> @endif
                {{ $sucursal->municipio }}
            </td>
            <td>
                <strong>NIT:</strong> {{ $empresa->nit }}<br>
                <strong>FACTURA N°:</strong> {{ $factura->numero_factura }}<br>
                <strong>CÓD. AUTORIZACIÓN:</strong><br>
                <span style="font-size:9px; word-break:break-all;">{{ $factura->cuf }}</span>
            </td>
        </tr>
    </table>

    <h1 style="margin-top:8px;">FACTURA</h1>
    <div class="sub">(Con Derecho a Crédito Fiscal)</div>

    {{-- ---- Comprador ---- --}}
    <table class="datos">
        <tr>
            <td style="width:55%;"><strong>Fecha:</strong> {{ $factura->fecha_emision?->format('d/m/Y h:i A') }}</td>
            <td><strong>NIT/CI/CEX:</strong> {{ $factura->comprador_numero_documento }}@if ($factura->comprador_complemento)-{{ $factura->comprador_complemento }}@endif</td>
        </tr>
        <tr>
            <td><strong>Nombre/Razón Social:</strong> {{ $factura->comprador_razon_social }}</td>
            <td><strong>Cod. Cliente:</strong> {{ $factura->comprador_numero_documento }}</td>
        </tr>
    </table>

    {{-- ---- Detalle ---- --}}
    <table class="detalle">
        <thead>
            <tr>
                <th>Código producto / servicio</th>
                <th class="derecha">Cantidad</th>
                <th>Unidad de medida</th>
                <th>Descripción</th>
                <th class="derecha">Precio unitario</th>
                <th class="derecha">Descuento</th>
                <th class="derecha">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($factura->items as $item)
                <tr>
                    <td>{{ $item->codigo_interno ?: $item->codigo_producto_sin }}</td>
                    <td class="derecha">{{ number_format((float) $item->cantidad, 2) }}</td>
                    <td>{{ $unidades[(string) $item->unidad_medida] ?? $item->unidad_medida }}</td>
                    <td>{{ $item->descripcion }}</td>
                    <td class="derecha">{{ number_format((float) $item->precio_unitario, 2) }}</td>
                    <td class="derecha">{{ number_format((float) $item->descuento, 2) }}</td>
                    <td class="derecha">{{ number_format((float) $item->subtotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ---- Totales ---- --}}
    <table class="totales">
        <tr><th>SUBTOTAL Bs</th><td class="derecha">{{ number_format($subtotalItems, 2) }}</td></tr>
        <tr><th>DESCUENTO Bs</th><td class="derecha">{{ number_format((float) $factura->descuento_global, 2) }}</td></tr>
        <tr><th>TOTAL Bs</th><td class="derecha">{{ number_format((float) $factura->monto_total, 2) }}</td></tr>
        <tr><th>MONTO GIFT CARD Bs</th><td class="derecha">{{ number_format((float) $factura->gift_card, 2) }}</td></tr>
        <tr class="total-pagar"><th>MONTO A PAGAR Bs</th><td class="derecha">{{ number_format($montoPagar, 2) }}</td></tr>
        <tr class="total-pagar"><th>IMPORTE BASE CRÉDITO FISCAL Bs</th><td class="derecha">{{ number_format((float) $factura->monto_total_sujeto_iva, 2) }}</td></tr>
    </table>

    <div class="literal">Son: {{ $montoLiteral }}</div>

    {{-- ---- Leyendas y QR ---- --}}
    <div class="pie">
        <p><strong>ESTA FACTURA CONTRIBUYE AL DESARROLLO DEL PAÍS, EL USO ILÍCITO SERÁ SANCIONADO PENALMENTE DE ACUERDO A LEY</strong></p>
        @if ($factura->leyenda)
            <p>{{ $factura->leyenda }}</p>
        @endif
        <p>“Este documento es la Representación Gráfica de un Documento Fiscal Digital emitido en una modalidad de facturación en línea”</p>
    </div>

    <div class="qr">
        <img src="{{ $qr }}" alt="QR de verificación" width="130" height="130"><br>
        <span style="font-size:8px;">{{ $urlVerificacion }}</span>
    </div>
</body>
</html>
