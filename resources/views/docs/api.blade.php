<!DOCTYPE html>
{{-- Documentacion publica de la API (Redoc sobre docs/integracion/openapi.yaml).
     Redoc va vendorizado en public/vendor/redoc (v2.5.3, MIT): la pagina no
     depende de un CDN en tiempo de ejecucion. --}}
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $nombreSistema }} — Documentación de la API</title>
    <meta name="description" content="API REST de facturación electrónica en línea ante el SIAT (Bolivia), documento sector 1 — Factura Compra Venta.">
    <style>
        :root {
            --bg: #0b1220; --acento: #3b82f6; --acento-2: #8b5cf6;
            --texto: #e2e8f0; --suave: #94a3b8; --linea: rgba(255,255,255,.10);
            --aviso-bg: rgba(59,130,246,.12); --aviso-borde: #3b82f6;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: #fff; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }
        header { display: flex; align-items: center; gap: 12px; padding: 16px 24px;
                 background: var(--bg); color: var(--texto); border-bottom: 1px solid var(--linea); }
        header .logo { width: 34px; height: 34px; border-radius: 9px; flex: none; display: grid; place-items: center;
                       font-weight: 800; color: #fff; background: linear-gradient(135deg, var(--acento), var(--acento-2)); }
        header .titulo { font-weight: 800; font-size: 1.05rem; }
        header .subtitulo { color: var(--suave); font-size: .8rem; }
        header .descargas { margin-left: auto; display: flex; gap: 8px; flex-wrap: wrap; }
        header .descargas a { color: var(--texto); font-size: .85rem; text-decoration: none;
                              border: 1px solid var(--linea); padding: 6px 12px; border-radius: 8px; }
        /* Lo primero que se ve, aunque Redoc no cargue: que hace falta para
           empezar. Lo mismo esta en la descripcion del spec, pero mas abajo. */
        .aviso { display: flex; gap: 12px; margin: 16px 24px; padding: 14px 18px; font-size: .9rem; line-height: 1.5;
                 background: var(--aviso-bg); border: 1px solid var(--aviso-borde); border-radius: 10px; color: #0f172a; }
        .aviso code { font-family: ui-monospace, monospace; font-size: .85em; }
        noscript { display: block; margin: 24px; }
    </style>
</head>
<body>
    <header>
        <div class="logo">S</div>
        <div>
            <div class="titulo">{{ $nombreSistema }} — Documentación de la API</div>
            <div class="subtitulo">Facturación electrónica en línea ante el SIAT · Sector 1, Compra Venta</div>
        </div>
        <nav class="descargas">
            <a href="{{ route('docs.api.postman.coleccion') }}">Colección Postman</a>
            <a href="{{ route('docs.api.postman.entorno') }}">Entorno</a>
            <a href="{{ route('docs.api.especificacion') }}" download="openapi.yaml">OpenAPI</a>
        </nav>
    </header>

    <div class="aviso">
        <span>ℹ️</span>
        <span>
            Para conectarse necesita el <strong>token de su empresa</strong> (<code>Authorization: Bearer …</code>),
            sus códigos de <strong>sucursal</strong> y <strong>punto de venta</strong> y, si usa webhooks, su
            <strong>secreto de webhook</strong>. Se los entrega el proveedor. Para probar sin programar, descargue la
            <strong>Colección Postman</strong> y el <strong>Entorno</strong> (sirven también en Thunder Client),
            impórtelos, pegue su token y ejecute las peticiones en orden. Empiece por <strong>Primeros pasos</strong> en el menú.
        </span>
    </div>

    <noscript>Esta documentación necesita JavaScript. La especificación está en
        <a href="{{ route('docs.api.especificacion') }}">openapi.yaml</a>.</noscript>

    <redoc spec-url="{{ route('docs.api.especificacion') }}"
           expand-responses="200,201"
           path-in-middle-panel
           hide-download-button
           theme='{"colors":{"primary":{"main":"#2563eb"}},"typography":{"fontFamily":"system-ui, -apple-system, Segoe UI, sans-serif","headings":{"fontFamily":"system-ui, -apple-system, Segoe UI, sans-serif"}},"sidebar":{"backgroundColor":"#f8fafc"}}'></redoc>

    <script src="{{ asset('vendor/redoc/redoc.standalone.js') }}"></script>
</body>
</html>
