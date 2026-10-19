<!DOCTYPE html>
{{-- Vista publica de una factura (enlace firmado). Antes de imprimir se
     elige el formato: hoja carta o ticket de 80 mm. Cada opcion abre el PDF
     directo en el visor del navegador, como la representacion del SIN, que
     ya trae descargar e imprimir. --}}
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    @php($documento = $factura->esNotaCreditoDebito() ? 'Nota crédito-débito' : 'Factura')
    <title>{{ $documento }} N° {{ $factura->numero_factura }} — {{ $factura->empresa->razon_social }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        html { background: #e2e8f0; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px;
               font: 15px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; color: #0f172a; }
        .caja { width: 100%; max-width: 440px; background: #fff; border-radius: 12px; padding: 28px 24px;
                box-shadow: 0 10px 30px rgba(15,23,42,.12); text-align: center; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .emisor { color: #475569; font-size: 14px; margin: 0 0 20px; }
        .aviso { padding: 10px 12px; border-radius: 8px; font-size: 14px; margin: 0 0 16px; }
        .aviso.anulada { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
        .aviso.prueba { background: #fffbeb; color: #92400e; border: 1px solid #fcd34d; }
        .pregunta { font-weight: 600; margin: 0 0 12px; }
        .opciones { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .opcion { display: block; padding: 18px 12px; border: 2px solid #cbd5e1; border-radius: 10px;
                  color: inherit; text-decoration: none; transition: border-color .15s, background-color .15s; }
        .opcion:hover, .opcion:focus-visible { border-color: #2563eb; background: #eff6ff; outline: none; }
        .opcion strong { display: block; font-size: 16px; }
        .opcion span { font-size: 13px; color: #64748b; }
        .verificar { display: inline-block; margin-top: 20px; font-size: 13px; color: #2563eb; }
        @media (max-width: 360px) { .opciones { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <main class="caja">
        <h1>{{ $documento }} N° {{ $factura->numero_factura }}</h1>
        <p class="emisor">{{ $factura->empresa->razon_social }} · NIT {{ $factura->empresa->nit }}</p>

        @if ($factura->estado === App\Models\Factura::ESTADO_ANULADA)
            <p class="aviso anulada"><strong>{{ mb_strtoupper($documento) }} ANULADA.</strong> Este documento ya no tiene validez.</p>
        @endif
        @if ($factura->empresa->codigo_ambiente === config('siat.codigos.ambiente.piloto'))
            <p class="aviso prueba"><strong>Ambiente de pruebas:</strong> este documento no tiene validez fiscal.</p>
        @endif

        <p class="pregunta">¿En qué formato la quiere imprimir?</p>
        <div class="opciones">
            <a class="opcion" href="{{ $urlPdf }}">
                <strong>Hoja carta</strong>
                <span>PDF tamaño carta</span>
            </a>
            <a class="opcion" href="{{ $urlRollo }}">
                <strong>Rollo</strong>
                <span>Ticket de 80 mm</span>
            </a>
        </div>

        <a class="verificar" href="{{ $urlVerificacion }}" target="_blank" rel="noopener">Verificar en el SIN</a>
    </main>
</body>
</html>
