<!DOCTYPE html>
{{-- Vista publica de una factura (enlace firmado). Muestra la misma
     representacion grafica que el PDF, con botones para descargarla,
     imprimirla en hoja o en ticket, y verificarla en el SIN. --}}
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Factura N° {{ $factura->numero_factura }} — {{ $factura->empresa->razon_social }}</title>
    @include('factura._estilos')
    <style>
        /* Estilos de la pagina (los de la factura vienen de _estilos). */
        html { background: #e2e8f0; }
        body { margin: 0; }
        .barra { position: sticky; top: 0; z-index: 2; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;
                 justify-content: center; padding: 12px 16px; background: #0b1220; }
        .barra a, .barra button { font: 600 14px system-ui, sans-serif; color: #fff; text-decoration: none; cursor: pointer;
                                  border: 1px solid rgba(255,255,255,.2); background: transparent; padding: 8px 14px; border-radius: 8px; }
        .barra .principal { background: #2563eb; border-color: #2563eb; }
        .estado { max-width: 820px; margin: 16px auto 0; padding: 10px 14px; border-radius: 8px;
                  font: 14px system-ui, sans-serif; text-align: center; }
        .estado.anulada { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
        .estado.prueba { background: #fffbeb; color: #92400e; border: 1px solid #fcd34d; }
        .hoja { max-width: 820px; margin: 16px auto 32px; background: #fff; padding: 32px 36px;
                box-shadow: 0 10px 30px rgba(15,23,42,.12); border-radius: 6px; }
        @media (max-width: 600px) { .hoja { padding: 16px; margin: 8px; } .detalle { font-size: 9px; } }
        /* Al imprimir desde el navegador: solo la factura. */
        @media print { html { background: #fff; } .barra, .estado { display: none; }
                       .hoja { box-shadow: none; margin: 0; padding: 0; max-width: none; } }
    </style>
</head>
<body>
    <nav class="barra">
        <a class="principal" href="{{ $urlPdf }}" target="_blank" rel="noopener">Descargar PDF</a>
        <a href="{{ $urlRollo }}" target="_blank" rel="noopener">Imprimir ticket (80 mm)</a>
        <button type="button" onclick="window.print()">Imprimir</button>
        <a href="{{ $urlVerificacion }}" target="_blank" rel="noopener">Verificar en el SIN</a>
    </nav>

    @if ($factura->estado === App\Models\Factura::ESTADO_ANULADA)
        <div class="estado anulada"><strong>FACTURA ANULADA.</strong> Este documento ya no tiene validez.</div>
    @endif
    @if ($factura->empresa->codigo_ambiente === config('siat.codigos.ambiente.piloto'))
        <div class="estado prueba"><strong>Ambiente de pruebas:</strong> esta factura no tiene validez fiscal.</div>
    @endif

    <main class="hoja">
        @include('factura._cuerpo')
    </main>
</body>
</html>
