<!DOCTYPE html>
{{-- Representacion grafica en ROLLO para impresoras termicas de ticket.
     Copia el diseno del ticket que genera el SIN (240 pt de ancho); en
     piloto lleva la marca "SIN VALOR LEGAL". El QR lleva t=1 (rollo).
     dompdf: solo CSS basico, sin flex. --}}
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 29pt 19pt 10pt; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 8pt; color: #000; margin: 0; line-height: 11pt; }
        .c { text-align: center; }
        .d { text-align: right; }
        .b { font-weight: bold; }
        .sep { border-top: 1pt dashed #000; margin: 5pt 0 3pt; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 0; vertical-align: top; }

        /* Marca de agua del ambiente de pruebas (piloto): tres, como el SIN. */
        .marca-agua { position: absolute; left: 0; width: 100%; text-align: center; z-index: -1;
                      font-size: 21pt; font-weight: bold; color: #d0d0d0; white-space: nowrap; }

        .cuf { word-wrap: break-word; line-height: 9.5pt; padding: 0 2pt; }
        .comprador td { padding-bottom: 1pt; line-height: 11pt; }
        .comprador .etiqueta { width: 52%; text-align: right; font-weight: bold; padding-right: 4pt; white-space: nowrap; }

        .detalle { font-size: 7pt; line-height: 10pt; }
        .detalle .unidad { padding-left: 5pt; padding-bottom: 3pt; }
        .detalle .linea td { border-bottom: 0.7pt dotted #000; padding-bottom: 1pt; }
        .totales { font-size: 7pt; margin-top: 2pt; }
        .totales td { padding: 1pt 0; line-height: 10pt; }
        .totales .etiqueta { width: 60%; text-align: right; }
        .totales .valor { text-align: right; padding-right: 3pt; }
        .totales .fuerte td { font-weight: bold; background: #f7f7f7; }
        .totales .chico .etiqueta { font-size: 6pt; }
        .literal { margin-top: 19pt; margin-bottom: 9pt; }

        .pie { text-align: center; margin-top: 12pt; }
        .pie p { margin: 0 0 9pt; }
        .pie .grande { font-size: 7pt; line-height: 8.2pt; }
        .pie .chica { font-size: 6pt; line-height: 7pt; }
        .qr { text-align: center; margin-top: 4pt; }
    </style>
</head>
<body>
    @php
        $empresa = $factura->empresa;
        $sucursal = $factura->puntoVenta->sucursal;
        $subtotalItems = $factura->items->sum(fn ($i) => (float) $i->subtotal);
        $montoPagar = (float) $factura->monto_total - (float) $factura->gift_card;
        $esPrueba = $empresa->codigo_ambiente === config('siat.codigos.ambiente.piloto');
        // El SIN escribe la unidad de medida en tipo oracion ("Bobinas").
        $enOracion = fn ($texto) => Illuminate\Support\Str::ucfirst(mb_strtolower((string) $texto));
    @endphp

    @if ($esPrueba)
        <div class="marca-agua" style="top:56pt;">SIN VALOR LEGAL</div>
        <div class="marca-agua" style="top:214pt;">SIN VALOR LEGAL</div>
        <div class="marca-agua" style="top:375pt;">SIN VALOR LEGAL</div>
    @endif

    <div class="c">
        <div class="b">FACTURA</div>
        <div class="b">CON DERECHO A CRÉDITO FISCAL</div>
        <div>{{ $empresa->razon_social }}</div>
        <div>{{ $sucursal->codigo_sucursal === 0 ? 'Casa Matriz' : 'Sucursal N° '.$sucursal->codigo_sucursal }}</div>
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
    <table class="comprador">
        <tr><td class="etiqueta">NOMBRE/RAZÓN SOCIAL:</td><td>{{ $factura->comprador_razon_social }}</td></tr>
        <tr><td class="etiqueta">NIT/CI/CEX:</td><td>{{ $factura->comprador_numero_documento }}@if ($factura->comprador_complemento)-{{ $factura->comprador_complemento }}@endif</td></tr>
        <tr><td class="etiqueta">COD. CLIENTE:</td><td>{{ $factura->comprador_numero_documento }}</td></tr>
        <tr><td class="etiqueta">FECHA DE EMISIÓN:</td><td>{{ $factura->fecha_emision?->format('d/m/Y h:i A') }}</td></tr>
    </table>

    <div class="sep"></div>
    <div class="c b">DETALLE</div>
    <table class="detalle">
        @foreach ($factura->items as $item)
            <tr><td colspan="2" class="b">{{ $item->codigo_interno ?: $item->codigo_producto_sin }} - {{ $item->descripcion }}</td></tr>
            <tr><td colspan="2" class="unidad">Unidad de Medida: {{ $enOracion($unidades[(string) $item->unidad_medida] ?? $item->unidad_medida) }}</td></tr>
            <tr class="{{ $loop->last ? 'linea' : '' }}">
                <td>{{ number_format((float) $item->cantidad, 2) }} X {{ number_format((float) $item->precio_unitario, 2) }} - {{ number_format((float) $item->descuento, 2) }}</td>
                <td class="d" style="padding-right:3pt;">{{ number_format((float) $item->subtotal, 2) }}</td>
            </tr>
        @endforeach
    </table>

    <table class="totales">
        <tr><td class="etiqueta">SUBTOTAL Bs</td><td class="valor">{{ number_format($subtotalItems, 2) }}</td></tr>
        <tr><td class="etiqueta">DESCUENTO Bs</td><td class="valor">{{ number_format((float) $factura->descuento_global, 2) }}</td></tr>
        <tr><td class="etiqueta">TOTAL Bs</td><td class="valor">{{ number_format((float) $factura->monto_total, 2) }}</td></tr>
        <tr><td class="etiqueta">MONTO GIFT CARD Bs</td><td class="valor">{{ number_format((float) $factura->gift_card, 2) }}</td></tr>
        <tr class="fuerte"><td class="etiqueta">MONTO A PAGAR Bs</td><td class="valor">{{ number_format($montoPagar, 2) }}</td></tr>
        <tr class="fuerte chico"><td class="etiqueta">IMPORTE BASE CRÉDITO FISCAL Bs</td><td class="valor">{{ number_format((float) $factura->monto_total_sujeto_iva, 2) }}</td></tr>
    </table>
    <div class="literal">Son: {{ mb_convert_case($montoLiteral, MB_CASE_TITLE) }}</div>

    <div class="sep"></div>
    <div class="pie">
        <p class="grande">ESTA FACTURA CONTRIBUYE AL DESARROLLO DEL PAÍS, EL USO ILÍCITO SERÁ SANCIONADO PENALMENTE DE ACUERDO A LEY</p>
        @if ($factura->leyenda)
            <p class="chica">{{ $factura->leyenda }}</p>
        @endif
        <p class="grande">“Este documento es la Representación Gráfica de un Documento Fiscal Digital emitido en una modalidad de facturación en línea”</p>
    </div>

    <div class="qr">
        <img src="{{ $qr }}" alt="QR" width="107" height="107">
    </div>
</body>
</html>
