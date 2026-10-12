{{-- Contenido de la representacion grafica (carta): lo mismo en el PDF y en
     la vista publica. Recibe factura, qr, urlVerificacion, montoLiteral y
     unidades (GeneradorPdf::datos). --}}
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
