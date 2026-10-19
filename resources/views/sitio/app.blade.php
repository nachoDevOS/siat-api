<!DOCTYPE html>
{{-- Portada publica del proveedor. Monta la app React de resources/js/sitio;
     los datos del servidor van en un JSON incrustado (InicioController).
     El contenido basico va tambien en <noscript> para buscadores y para
     quien navega sin JavaScript. --}}
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="API de facturación electrónica en línea ante el SIAT de Impuestos Nacionales de Bolivia. Su sistema manda la venta y recibe la factura lista.">
    <meta name="color-scheme" content="light dark">
    <title>{{ $datos['marca'] }} · Facturación electrónica en línea para Bolivia</title>
    @viteReactRefresh
    @vite('resources/js/sitio/main.jsx')
</head>
<body>
    <div id="sitio"></div>
    <script id="sitio-datos" type="application/json">@json($datos)</script>
    <noscript>
        <h1>{{ $datos['marca'] }}: facturación electrónica en línea ante el SIAT</h1>
        <p>Su sistema de ventas manda la venta en JSON y recibe la factura con CUF, firmada y lista para imprimir.</p>
        <p><a href="{{ $datos['rutas']['documentacion'] }}">Documentación de la API</a></p>
    </noscript>
</body>
</html>
