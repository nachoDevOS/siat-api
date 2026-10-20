{{-- Contenido de la representacion grafica (PDF carta). Recibe factura, qr,
     urlVerificacion, montoLiteral y unidades (GeneradorPdf::datos). Copia el
     diseno de la representacion que genera el SIN; en piloto lleva la marca
     "SIN VALOR LEGAL". --}}
@php
    $empresa = $factura->empresa;
    $sucursal = $factura->puntoVenta->sucursal;
    $esPrueba = $empresa->codigo_ambiente === config('siat.codigos.ambiente.piloto');
    // El SIN escribe la unidad de medida en tipo oracion ("Bobinas").
    $enOracion = fn ($texto) => Illuminate\Support\Str::ucfirst(mb_strtolower((string) $texto));
@endphp

<div class="factura">
    @if ($esPrueba)
        <div class="marca-agua">SIN VALOR LEGAL</div>
    @endif

    {{-- ---- Emisor ---- --}}
    <table class="encabezado">
        <tr>
            <td class="emisor">
                <div class="nombre">{{ $empresa->razon_social }}</div>
                <div class="casa">{{ $sucursal->codigo_sucursal === 0 ? 'CASA MATRIZ' : 'SUCURSAL N° '.$sucursal->codigo_sucursal }}</div>
                No. Punto de Venta {{ $factura->puntoVenta->codigo_punto_venta }}<br>
                {{ $sucursal->direccion }}<br>
                @if ($sucursal->telefono) Teléfono: {{ $sucursal->telefono }}<br> @endif
                {{ $sucursal->municipio }}
            </td>
            <td style="width:34.5%;"></td>
            <td>
                <table class="identificacion">
                    <tr><td class="etiqueta">NIT</td><td>{{ $empresa->nit }}</td></tr>
                    <tr><td class="etiqueta">FACTURA N°</td><td>{{ $factura->numero_factura }}</td></tr>
                    <tr><td class="etiqueta">CÓD. AUTORIZACIÓN</td><td class="cuf">{!! implode('<br>', str_split((string) $factura->cuf, 16)) !!}</td></tr>
                    {{-- El formato V3 muestra el codigoInterno debajo del CUF. --}}
                    <tr><td></td><td style="text-align:center;">{{ $empresa->codigo_interno }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="titulo">
        <h1>FACTURA</h1>
        <div class="sub">(Con Derecho a Crédito Fiscal)</div>
    </div>

    {{-- ---- Comprador ---- --}}
    <table class="comprador">
        <tr>
            <td class="etiqueta" style="width:21.1%;">Fecha:</td>
            <td>{{ $factura->fecha_emision?->format('d/m/Y h:i A') }}</td>
            <td class="etiqueta" style="width:16%; text-align:right; padding-right:20px;">NIT/CI/CEX:</td>
            <td style="width:14.2%;">{{ $factura->comprador_numero_documento }}@if ($factura->comprador_complemento)-{{ $factura->comprador_complemento }}@endif</td>
        </tr>
        <tr>
            <td class="etiqueta">Nombre/Razón Social:</td>
            <td>{{ $factura->comprador_razon_social }}</td>
            <td class="etiqueta" style="text-align:right; padding-right:20px;">Cod. Cliente:</td>
            <td>{{ $factura->comprador_numero_documento }}</td>
        </tr>
    </table>

    {{-- ---- Detalle y totales ---- --}}
    <table class="detalle">
        <thead>
            <tr>
                <th style="width:14.5%;">Código producto / servicio</th>
                <th style="width:10.7%;">Cantidad</th>
                <th style="width:10%;">Unidad de medida</th>
                <th>Descripción</th>
                <th style="width:12.7%;">Precio neto unitario</th>
                <th style="width:12.5%;">Descuento</th>
                <th style="width:13.5%;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($factura->items as $item)
                <tr>
                    <td>{{ $item->codigo_interno ?: $item->codigo_producto_sin }}</td>
                    <td class="derecha">{{ number_format((float) $item->cantidad, 2) }}</td>
                    <td class="texto">{{ $enOracion($unidades[(string) $item->unidad_medida] ?? $item->unidad_medida) }}</td>
                    <td class="texto">{{ $item->descripcion }}</td>
                    <td class="derecha">{{ number_format((float) $item->precio_unitario, 2) }}</td>
                    <td class="derecha">{{ number_format((float) $item->descuento, 2) }}</td>
                    <td class="derecha">{{ number_format((float) $item->subtotal, 2) }}</td>
                </tr>
            @endforeach
            <tr>
                <td class="literal" colspan="4" rowspan="6">Son: {{ mb_convert_case($montoLiteral, MB_CASE_TITLE) }}</td>
                {{-- Totales y orden del formato grafico V3 (IVA Transparente). --}}
                <td class="total-etiqueta" colspan="2">SUBTOTAL Bs</td>
                <td class="total-valor">{{ number_format((float) $factura->subtotal, 2) }}</td>
            </tr>
            <tr>
                <td class="total-etiqueta" colspan="2">MONTO GIFT CARD Bs</td>
                <td class="total-valor">{{ number_format((float) $factura->gift_card, 2) }}</td>
            </tr>
            <tr>
                <td class="total-etiqueta" colspan="2">DESCUENTO Bs</td>
                <td class="total-valor">{{ number_format((float) $factura->descuento_global, 2) }}</td>
            </tr>
            <tr>
                <td class="total-etiqueta" colspan="2">TOTAL PRECIO NETO Bs</td>
                <td class="total-valor">{{ number_format((float) $factura->total_precio_neto, 2) }}</td>
            </tr>
            <tr>
                <td class="total-etiqueta" colspan="2">IVA Bs</td>
                <td class="total-valor">{{ number_format((float) $factura->iva, 2) }}</td>
            </tr>
            <tr>
                <td class="total-etiqueta fuerte" colspan="2">MONTO A PAGAR Bs</td>
                <td class="total-valor fuerte">{{ number_format((float) $factura->monto_total, 2) }}</td>
            </tr>
        </tbody>
    </table>

    {{-- ---- Leyendas y QR ---- --}}
    <table class="pie">
        <tr>
            <td class="leyendas">
                <p class="mayusculas">ESTA FACTURA CONTRIBUYE AL DESARROLLO DEL PAÍS, EL USO ILÍCITO SERÁ SANCIONADO PENALMENTE DE ACUERDO A LEY</p>
                @if ($factura->leyenda)
                    <p>{{ $factura->leyenda }}</p>
                @endif
                <p>“Este documento es la Representación Gráfica de un Documento Fiscal Digital emitido en una modalidad de facturación en línea”</p>
            </td>
            <td class="codigo-qr">
                <a href="{{ $urlVerificacion }}"><img src="{{ $qr }}" alt="QR de verificación" width="82" height="82"></a>
            </td>
        </tr>
    </table>
</div>
