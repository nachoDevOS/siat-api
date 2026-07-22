<!DOCTYPE html>
{{-- Representacion imprimible de la factura. El documento legal es el XML firmado. --}}
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        /* Estilos simples: el PDF se genera con dompdf, que soporta CSS basico. */
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        .encabezado { text-align: center; margin-bottom: 10px; }
        .encabezado h1 { font-size: 15px; margin: 0; }
        .caja { border: 1px solid #333; padding: 8px; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #999; padding: 4px; text-align: left; }
        th { background: #eee; }
        .derecha { text-align: right; }
        .totales { width: 45%; margin-left: 55%; }
        .qr { text-align: center; margin-top: 10px; }
        .leyenda { font-size: 10px; margin-top: 8px; font-style: italic; }
        .pie { font-size: 9px; text-align: center; margin-top: 8px; color: #555; }
    </style>
</head>
<body>
    <div class="encabezado">
        <h1>{{ $factura->empresa->razon_social }}</h1>
        <div>{{ $factura->empresa->nombre_comercial }}</div>
        <div>NIT: {{ $factura->empresa->nit }}</div>
        <strong>FACTURA N° {{ $factura->numero_factura }}</strong>
    </div>

    <div class="caja">
        <div><strong>CUF:</strong> {{ $factura->cuf }}</div>
        <div><strong>Fecha de emision:</strong> {{ $factura->fecha_emision?->format('d/m/Y H:i') }}</div>
        <div><strong>Sucursal:</strong> {{ $factura->puntoVenta->sucursal->codigo_sucursal }}
             &middot; <strong>Punto de venta:</strong> {{ $factura->puntoVenta->codigo_punto_venta }}</div>
    </div>

    <div class="caja">
        <div><strong>Nombre/Razon social:</strong> {{ $factura->comprador_razon_social }}</div>
        <div><strong>Documento:</strong> {{ $factura->comprador_numero_documento }}{{ $factura->comprador_complemento }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Codigo</th>
                <th>Descripcion</th>
                <th class="derecha">Cantidad</th>
                <th class="derecha">P. unitario</th>
                <th class="derecha">Descuento</th>
                <th class="derecha">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($factura->items as $item)
                <tr>
                    <td>{{ $item->codigo_interno ?: $item->codigo_producto_sin }}</td>
                    <td>{{ $item->descripcion }}</td>
                    <td class="derecha">{{ number_format((float) $item->cantidad, 2) }}</td>
                    <td class="derecha">{{ number_format((float) $item->precio_unitario, 2) }}</td>
                    <td class="derecha">{{ number_format((float) $item->descuento, 2) }}</td>
                    <td class="derecha">{{ number_format((float) $item->subtotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totales">
        <tr><th>Descuento</th><td class="derecha">{{ number_format((float) $factura->descuento_global, 2) }}</td></tr>
        <tr><th>Gift Card</th><td class="derecha">{{ number_format((float) $factura->gift_card, 2) }}</td></tr>
        <tr><th>Total</th><td class="derecha"><strong>{{ number_format((float) $factura->monto_total, 2) }}</strong></td></tr>
        <tr><th>Importe sujeto a IVA</th><td class="derecha">{{ number_format((float) $factura->monto_total_sujeto_iva, 2) }}</td></tr>
    </table>

    <div class="qr">
        <img src="{{ $qr }}" alt="QR de verificacion" width="150" height="150">
    </div>

    @if ($factura->leyenda)
        <div class="leyenda">{{ $factura->leyenda }}</div>
    @endif

    <div class="pie">
        Esta factura contribuye al desarrollo del pais. El uso ilicito sera sancionado penalmente de acuerdo a Ley.<br>
        Verifique su factura en: {{ $urlVerificacion }}
    </div>
</body>
</html>
