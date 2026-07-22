<!DOCTYPE html>
{{-- Layout del panel /admin. CSS inline simple para no depender del build de Vite. --}}
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('titulo', 'Panel SIAT')</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, sans-serif; color: #1f2937; background: #f3f4f6; }
        header { background: #111827; color: #fff; padding: 12px 20px; display: flex; gap: 20px; align-items: center; }
        header a { color: #d1d5db; text-decoration: none; font-size: 14px; }
        header a:hover { color: #fff; }
        header .marca { font-weight: 700; color: #fff; }
        main { max-width: 1000px; margin: 20px auto; padding: 0 16px; }
        .tarjeta { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        h1 { font-size: 20px; } h2 { font-size: 16px; margin-top: 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 8px; border-bottom: 1px solid #e5e7eb; font-size: 14px; }
        th { color: #6b7280; font-weight: 600; }
        .btn { display: inline-block; background: #2563eb; color: #fff; padding: 8px 14px; border-radius: 6px;
               text-decoration: none; border: 0; cursor: pointer; font-size: 14px; }
        .btn.gris { background: #6b7280; } .btn.rojo { background: #dc2626; } .btn:disabled { background: #9ca3af; cursor: not-allowed; }
        .campo { margin-bottom: 12px; } label { display: block; font-size: 13px; margin-bottom: 4px; color: #374151; }
        input, select { width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }
        .aviso { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; padding: 10px; border-radius: 6px; margin-bottom: 16px; }
        .clave { background: #fffbeb; border: 1px solid #fcd34d; color: #92400e; padding: 10px; border-radius: 6px;
                 margin-bottom: 16px; font-family: monospace; word-break: break-all; }
        .error { color: #dc2626; font-size: 13px; }
        .pill { padding: 2px 8px; border-radius: 999px; font-size: 12px; background: #e5e7eb; }
        .ok { color: #059669; } .no { color: #dc2626; }
    </style>
</head>
<body>
    <header>
        <span class="marca">SIAT · Panel</span>
        <a href="{{ route('admin.empresas.index') }}">Empresas</a>
        <a href="{{ route('admin.monitor') }}">Monitor</a>
        {{-- Usuario logueado y salida, alineados a la derecha. --}}
        <span style="margin-left:auto; color:#9ca3af; font-size:13px;">{{ auth()->user()?->name }}</span>
        <form method="POST" action="{{ route('logout') }}" style="margin:0;">
            @csrf
            <button type="submit"
                    style="background:none;border:0;color:#d1d5db;cursor:pointer;font-size:14px;">Salir</button>
        </form>
    </header>
    <main>
        @if (session('estado'))
            <div class="aviso">{{ session('estado') }}</div>
        @endif
        @if (session('api_key'))
            <div class="clave">API key (guardela, no se vuelve a mostrar): {{ session('api_key') }}</div>
        @endif
        @yield('contenido')
    </main>
</body>
</html>
