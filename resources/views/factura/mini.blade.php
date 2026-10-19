<!DOCTYPE html>
{{-- Mini ticket: comprobante corto para la caja, con lo justo para cobrar y
     que el comprador verifique la factura con el QR. NO reemplaza a la
     representacion grafica (sin detalle de items, sin leyendas): la factura
     completa es el PDF carta o el rollo. dompdf: solo CSS basico. --}}
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 14pt 12pt; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 8pt; color: #000; margin: 0; text-align: center; line-height: 11pt; }
        .b { font-weight: bold; }
        .monto { font-size: 15pt; font-weight: bold; margin: 6pt 0; line-height: 17pt; }
        .chico { font-size: 6.5pt; line-height: 8pt; }
        .prueba { font-weight: bold; border: 1pt solid #000; padding: 2pt; margin-bottom: 4pt; }
    </style>
</head>
<body>
    @php($esNota = $factura->esNotaCreditoDebito())
    @if ($factura->empresa->codigo_ambiente === config('siat.codigos.ambiente.piloto'))
        <div class="prueba">SIN VALOR LEGAL</div>
    @endif
    <div class="b">{{ $factura->empresa->razon_social }}</div>
    <div>NIT {{ $factura->empresa->nit }}</div>
    <div class="b" style="margin-top:4pt;">{{ $esNota ? 'NOTA CRÉDITO DÉBITO' : 'FACTURA' }} N° {{ $factura->numero_factura }}</div>
    <div>{{ $factura->fecha_emision?->format('d/m/Y H:i') }}</div>
    <div class="monto">Bs {{ number_format($esNota ? (float) $factura->monto_total : (float) $factura->monto_total - (float) $factura->gift_card, 2) }}</div>
    <img src="{{ $qr }}" alt="QR de verificación" width="96" height="96">
    <div class="chico" style="margin-top:4pt;">Escanee el QR para ver y verificar la factura completa en el SIN.</div>
</body>
</html>
