{{-- Representacion grafica de la nota de credito-debito (sector 24). Recibe
     lo mismo que _cuerpo (GeneradorPdf::datos). Sigue el formato del SIN:
     identifica la factura que se ajusta, lista sus items originales y los
     devueltos, y cierra con el credito fiscal que se revierte (13%). --}}
@php
    $empresa = $factura->empresa;
    $sucursal = $factura->puntoVenta->sucursal;
    $origen = $factura->facturaOrigen;
    [$devueltos, $originales] = $factura->items->partition(fn ($i) => $i->esDevuelto());
    $esPrueba = $empresa->codigo_ambiente === config('siat.codigos.ambiente.piloto');
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
                    <tr><td class="etiqueta">NOTA N°</td><td>{{ $factura->numero_factura }}</td></tr>
                    <tr><td class="etiqueta">CÓD. AUTORIZACIÓN</td><td class="cuf">{!! implode('<br>', str_split((string) $factura->cuf, 16)) !!}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="titulo">
        <h1>NOTA CRÉDITO DÉBITO</h1>
    </div>

    {{-- ---- Comprador y factura original ---- --}}
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
        <tr>
            <td class="etiqueta">N° Factura:</td>
            <td>{{ $origen?->numero_factura }}</td>
            <td class="etiqueta" style="text-align:right; padding-right:20px;">Fecha Factura:</td>
            <td>{{ $origen?->fecha_emision?->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td class="etiqueta">N° Autorización/CUF:</td>
            <td colspan="3" class="cuf">{{ $origen?->cuf }}</td>
        </tr>
    </table>

    @foreach ([
        ['titulo' => 'DATOS DE LA TRANSACCIÓN ORIGINAL', 'items' => $originales],
        ['titulo' => 'DETALLE DE LA DEVOLUCIÓN O RESCISIÓN DE SERVICIO', 'items' => $devueltos],
    ] as $seccion)
        <p class="fuerte" style="margin:14px 0 4px;">{{ $seccion['titulo'] }}</p>
        <table class="detalle">
            <thead>
                <tr>
                    <th style="width:14.5%;">Código producto / servicio</th>
                    <th style="width:10.7%;">Cantidad</th>
                    <th style="width:10%;">Unidad de medida</th>
                    <th>Descripción</th>
                    <th style="width:12.7%;">Precio unitario</th>
                    <th style="width:12.5%;">Descuento</th>
                    <th style="width:13.5%;">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($seccion['items'] as $item)
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
            </tbody>
        </table>
    @endforeach

    {{-- ---- Totales ---- --}}
    <table class="detalle" style="margin-top:8px;">
        <tbody>
            <tr>
                <td class="literal" rowspan="3">Son: {{ mb_convert_case($montoLiteral, MB_CASE_TITLE) }}</td>
                <td class="total-etiqueta" style="width:38%;">MONTO TOTAL DEVUELTO Bs</td>
                <td class="total-valor" style="width:13.5%;">{{ number_format((float) $factura->subtotal, 2) }}</td>
            </tr>
            <tr>
                <td class="total-etiqueta">MONTO DESCUENTO Bs</td>
                <td class="total-valor">{{ number_format((float) $factura->descuento_global, 2) }}</td>
            </tr>
            <tr>
                <td class="total-etiqueta fuerte">MONTO EFECTIVO DEL CRÉDITO O DÉBITO (13%) Bs</td>
                <td class="total-valor fuerte">{{ number_format((float) $factura->monto_efectivo_credito_debito, 2) }}</td>
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
