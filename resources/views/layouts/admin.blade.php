<!DOCTYPE html>
{{-- Layout del panel /admin: sidebar fija + topbar. CSS inline para no depender del build de Vite. --}}
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('titulo', 'Panel SIAT')</title>
    <style>
        :root {
            --bg: #f1f5f9; --panel: #ffffff; --borde: #e2e8f0; --texto: #0f172a;
            --suave: #64748b; --primario: #2563eb; --primario-2: #1d4ed8;
            --sidebar: #0b1220; --sidebar-2: #111a2e; --sidebar-txt: #94a3b8;
            --ok: #059669; --no: #dc2626; --ancho-side: 236px;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
               color: var(--texto); background: var(--bg); }

        /* ---- Estructura: sidebar + contenido ---- */
        .capa { display: flex; min-height: 100vh; }
        .side { width: var(--ancho-side); flex-shrink: 0; background: linear-gradient(180deg,var(--sidebar),var(--sidebar-2));
                color: #e2e8f0; display: flex; flex-direction: column; position: sticky; top: 0; height: 100vh; }
        .side .marca { padding: 20px 20px 14px; font-weight: 800; font-size: 18px; color: #fff;
                       display: flex; align-items: center; gap: 10px; letter-spacing: .3px; }
        .side .marca .logo { width: 30px; height: 30px; border-radius: 8px; flex-shrink: 0;
                             background: linear-gradient(135deg,#3b82f6,#8b5cf6); display: grid; place-items: center;
                             font-size: 14px; color: #fff; }
        .side .grupo { padding: 8px 12px; font-size: 11px; text-transform: uppercase; letter-spacing: .8px;
                       color: #475569; margin-top: 8px; }
        .side nav a { display: flex; align-items: center; gap: 11px; padding: 10px 14px; margin: 2px 10px;
                      border-radius: 8px; color: var(--sidebar-txt); text-decoration: none; font-size: 14px;
                      transition: background .15s, color .15s; }
        .side nav a:hover { background: rgba(148,163,184,.1); color: #fff; }
        .side nav a.activo { background: var(--primario); color: #fff; box-shadow: 0 4px 12px rgba(37,99,235,.35); }
        .side nav a .ic { width: 18px; text-align: center; font-size: 15px; }
        .side .pie { margin-top: auto; padding: 14px; border-top: 1px solid rgba(148,163,184,.12); }
        .side .pie .quien { font-size: 13px; color: #cbd5e1; margin-bottom: 8px; }
        .side .pie .quien small { display: block; color: #64748b; font-size: 11px; }
        .side .pie button { width: 100%; background: rgba(148,163,184,.12); color: #e2e8f0; border: 0;
                            padding: 9px; border-radius: 8px; cursor: pointer; font-size: 13px; }
        .side .pie button:hover { background: rgba(239,68,68,.2); color: #fecaca; }

        .zona { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .topbar { background: var(--panel); border-bottom: 1px solid var(--borde); padding: 14px 24px;
                  display: flex; align-items: center; gap: 16px; position: sticky; top: 0; z-index: 5; }
        .topbar h1 { font-size: 19px; margin: 0; }
        .topbar .abrir { display: none; background: none; border: 0; font-size: 22px; cursor: pointer; color: var(--texto); }
        main { padding: 24px; max-width: 1200px; width: 100%; margin: 0 auto; }

        /* ---- Tarjetas y componentes heredados ---- */
        .tarjeta { background: var(--panel); border: 1px solid var(--borde); border-radius: 12px; padding: 18px;
                   margin-bottom: 18px; box-shadow: 0 1px 3px rgba(15,23,42,.04); }
        h1 { font-size: 22px; } h2 { font-size: 16px; margin-top: 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 9px 8px; border-bottom: 1px solid var(--borde); font-size: 14px; }
        th { color: var(--suave); font-weight: 600; }
        .btn { display: inline-block; background: var(--primario); color: #fff; padding: 8px 14px; border-radius: 8px;
               text-decoration: none; border: 0; cursor: pointer; font-size: 14px; transition: background .15s; }
        .btn:hover { background: var(--primario-2); }
        .btn.gris { background: #64748b; } .btn.gris:hover { background: #475569; }
        .btn.rojo { background: #dc2626; } .btn.rojo:hover { background: #b91c1c; }
        .btn:disabled { background: #cbd5e1; cursor: not-allowed; }
        .campo { margin-bottom: 12px; } label { display: block; font-size: 13px; margin-bottom: 4px; color: #374151; }
        input, select { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px;
                        background: #fff; }
        input:focus, select:focus { outline: 2px solid rgba(37,99,235,.35); border-color: var(--primario); }
        .aviso { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; padding: 12px 14px; border-radius: 10px;
                 margin-bottom: 18px; }
        .clave { background: #fffbeb; border: 1px solid #fcd34d; color: #92400e; padding: 12px 14px; border-radius: 10px;
                 margin-bottom: 18px; font-family: monospace; word-break: break-all; }
        .error { color: #dc2626; font-size: 13px; }
        .pill { padding: 3px 10px; border-radius: 999px; font-size: 12px; background: #e2e8f0; color: #334155; }
        .ok { color: var(--ok); font-weight: 600; } .no { color: var(--no); font-weight: 600; }

        /* ---- Paleta unica de estados (ver App\Services\Panel\EstadosVisuales) ----
           Un color significa lo mismo en TODO el panel:
           verde listo · azul en curso · violeta hito · ambar atencion · rojo bloqueante */
        .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600;
                 border: 1px solid transparent; white-space: nowrap; }
        .badge-gris    { background: #f1f5f9; color: #475569; border-color: #e2e8f0; }
        .badge-azul    { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
        .badge-violeta { background: #f5f3ff; color: #6d28d9; border-color: #ddd6fe; }
        .badge-verde   { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
        .badge-ambar   { background: #fffbeb; color: #b45309; border-color: #fde68a; }
        .badge-rojo    { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }

        /* ---- Semaforo de vigencia ---- */
        .semaforo { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #334155; }
        .semaforo .txt { color: var(--suave); }
        .punto { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; display: inline-block; }
        .punto-verde { background: #059669; } .punto-ambar { background: #d97706; }
        .punto-rojo  { background: #dc2626; } .punto-gris  { background: #94a3b8; }
        .punto-azul  { background: #2563eb; } .punto-violeta { background: #7c3aed; }

        /* ---- Stepper del ciclo de vida ---- */
        .stepper { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .stepper .paso { display: flex; align-items: center; gap: 7px; }
        .stepper .marca { width: 24px; height: 24px; border-radius: 50%; display: grid; place-items: center;
                          font-size: 12px; font-weight: 700; background: #e2e8f0; color: #64748b; flex-shrink: 0; }
        .stepper .rotulo { font-size: 12px; color: var(--suave); white-space: nowrap; }
        .stepper .paso-hecho   .marca { background: #059669; color: #fff; }
        .stepper .paso-actual  .marca { background: var(--primario); color: #fff; box-shadow: 0 0 0 3px rgba(37,99,235,.18); }
        .stepper .paso-actual  .rotulo { color: var(--texto); font-weight: 600; }
        .stepper .paso-alerta  .marca { background: #dc2626; color: #fff; box-shadow: 0 0 0 3px rgba(220,38,38,.18); }
        .stepper .paso-alerta  .rotulo { color: #b91c1c; font-weight: 600; }
        .stepper .linea { flex: 1; min-width: 16px; height: 2px; background: #e2e8f0; }
        .stepper .linea-hecha { background: #059669; }
        .stepper-compacto .marca { width: 18px; height: 18px; font-size: 10px; }
        .stepper-compacto .linea { min-width: 10px; }

        /* ---- Checklist de requisitos ---- */
        .chk { list-style: none; margin: 0; padding: 0; }
        .chk li { display: flex; gap: 10px; padding: 9px 0; border-bottom: 1px solid var(--borde); }
        .chk li:last-child { border-bottom: 0; }
        .chk .caja { width: 18px; height: 18px; border-radius: 5px; display: grid; place-items: center; flex-shrink: 0;
                     font-size: 11px; font-weight: 700; margin-top: 2px; }
        .chk .si { background: #d1fae5; color: #047857; } .chk .nope { background: #fee2e2; color: #b91c1c; }
        .chk .titulo { font-size: 14px; }
        .chk .detalle { font-size: 12px; color: var(--suave); margin-top: 2px; }

        /* ---- Barra de progreso generica ---- */
        .progreso { height: 8px; background: #eef2f7; border-radius: 999px; overflow: hidden; }
        .progreso span { display: block; height: 100%; background: linear-gradient(90deg,#3b82f6,#059669); }

        /* ---- Filtros de listado ---- */
        .filtros { display: flex; gap: 10px; align-items: end; flex-wrap: wrap; }
        .filtros .campo { margin: 0; } .filtros input, .filtros select { min-width: 190px; }

        /* ---- Toggle de sidebar en movil (checkbox puro CSS) ---- */
        #menu { display: none; }
        @media (max-width: 860px) {
            .side { position: fixed; z-index: 20; left: calc(-1 * var(--ancho-side) - 4px); transition: left .2s; }
            #menu:checked ~ .capa .side { left: 0; box-shadow: 0 0 40px rgba(0,0,0,.4); }
            .topbar .abrir { display: block; }
        }
    </style>
</head>
<body>
    <input type="checkbox" id="menu">
    <div class="capa">
        <aside class="side">
            <div class="marca"><span class="logo">S</span> SIAT · Panel</div>

            <div class="grupo">General</div>
            <nav>
                <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'activo' : '' }}">
                    <span class="ic">▦</span> Dashboard
                </a>
                <a href="{{ route('admin.empresas.index') }}" class="{{ request()->routeIs('admin.empresas.*') ? 'activo' : '' }}">
                    <span class="ic">▤</span> Empresas
                </a>
                <a href="{{ route('admin.monitor') }}" class="{{ request()->routeIs('admin.monitor') ? 'activo' : '' }}">
                    <span class="ic">◷</span> Monitor
                </a>
            </nav>

            <div class="grupo">Integracion</div>
            <nav>
                <a href="{{ route('admin.api') }}" class="{{ request()->routeIs('admin.api') ? 'activo' : '' }}">
                    <span class="ic">⚡</span> Administrar API
                </a>
                <a href="{{ route('admin.configuracion') }}" class="{{ request()->routeIs('admin.configuracion') ? 'activo' : '' }}">
                    <span class="ic">⚙</span> Configuracion
                </a>
            </nav>

            <div class="pie">
                <div class="quien">{{ auth()->user()?->name }}<small>{{ auth()->user()?->email }}</small></div>
                <form method="POST" action="{{ route('logout') }}" style="margin:0;">
                    @csrf
                    <button type="submit">Cerrar sesion</button>
                </form>
            </div>
        </aside>

        <div class="zona">
            <div class="topbar">
                <label for="menu" class="abrir">☰</label>
                <h1>@yield('titulo', 'Panel SIAT')</h1>
            </div>
            <main>
                @if (session('estado'))
                    <div class="aviso">{{ session('estado') }}</div>
                @endif
                @if (session('api_key'))
                    <div class="clave">API key (guardela, no se vuelve a mostrar): {{ session('api_key') }}</div>
                @endif
                @yield('contenido')
            </main>
        </div>
    </div>
</body>
</html>
