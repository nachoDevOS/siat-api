<!DOCTYPE html>
{{-- Documentacion publica de la API: app React de resources/js/docs. Las
     guias salen de docs/integracion/API.md y la referencia de openapi.yaml.
     Lo que necesita del servidor va en el JSON incrustado. --}}
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="description" content="Documentación de la API de facturación electrónica en línea ante el SIAT: autenticación, endpoints, ejemplos, webhooks y Postman.">
    <title>Documentación de la API · {{ $datos['marca'] }}</title>
    @viteReactRefresh
    @vite('resources/js/docs/main.jsx')
</head>
<body>
    <div id="docs"></div>
    <script id="docs-datos" type="application/json">@json($datos)</script>
    <noscript>
        <h1>Documentación de la API · {{ $datos['marca'] }}</h1>
        <p>Todas las llamadas llevan el token de su empresa en la cabecera Authorization.</p>
        <p>
            <a href="{{ $datos['rutas']['openapi'] }}">Especificación OpenAPI</a> ·
            <a href="{{ $datos['rutas']['coleccion'] }}">Colección de Postman</a> ·
            <a href="{{ $datos['rutas']['entorno'] }}">Entorno de Postman</a>
        </p>
    </noscript>
</body>
</html>
