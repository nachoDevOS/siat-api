{{-- Estilos de "Comunicacion SIAT". Usan los colores del layout: verde
     aceptada, ambar rechazada por el SIN, rojo sin respuesta. --}}
<style>
    .cs-cabeza { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
    .cs-sub { color: var(--suave); font-size: 13px; margin: 0; }
    .cs-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    .cs-vacio { color: var(--suave); font-size: 13px; font-style: italic; }
    .cs-titulo { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
    .cs-titulo h2 { margin: 0; }

    /* Animacion del encabezado: un pulso viaja de tu API al SIAT. */
    .cs-flujo { display: flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 700; }
    .cs-nodo { padding: 6px 12px; border-radius: 999px; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .cs-nodo-sin { background: #f5f3ff; color: #6d28d9; border-color: #ddd6fe; }
    .cs-cable { position: relative; width: 90px; height: 2px; background: linear-gradient(90deg, #bfdbfe, #ddd6fe); border-radius: 2px; overflow: hidden; }
    .cs-cable i { position: absolute; top: -2px; width: 18px; height: 6px; border-radius: 6px;
                  background: linear-gradient(90deg, transparent, #6366f1, transparent); animation: cs-viaje 1.8s ease-in-out infinite; }
    @keyframes cs-viaje { from { left: -20px; } to { left: 100%; } }
    @media (prefers-reduced-motion: reduce) { .cs-cable i { animation: none; left: 36px; } }

    /* Ultimo problema */
    .cs-alerta { display: flex; align-items: center; gap: 14px; padding: 14px 16px; border-radius: 12px; margin-bottom: 18px;
                 text-decoration: none; color: inherit; border: 1px solid; transition: transform .15s, box-shadow .15s; }
    .cs-alerta:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(15,23,42,.08); }
    .cs-alerta-ambar { background: #fffbeb; border-color: #fde68a; }
    .cs-alerta-rojo { background: #fef2f2; border-color: #fecaca; }
    .cs-alerta-icono { width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; font-weight: 800; flex-shrink: 0; color: #fff; }
    .cs-alerta-ambar .cs-alerta-icono { background: #d97706; } .cs-alerta-rojo .cs-alerta-icono { background: #dc2626; }
    .cs-alerta-cuerpo { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; font-size: 14px; }
    .cs-alerta-cuerpo small { color: var(--suave); }
    .cs-alerta-ir { font-weight: 600; font-size: 13px; white-space: nowrap; color: var(--primario); }

    /* Indicadores */
    .cs-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; margin-bottom: 18px; }
    .cs-kpi { position: relative; background: var(--panel); border: 1px solid var(--borde); border-radius: 12px; padding: 16px;
              display: flex; flex-direction: column; gap: 4px; overflow: hidden; border-top: 3px solid #94a3b8; }
    .cs-kpi-verde { border-top-color: #059669; } .cs-kpi-ambar { border-top-color: #d97706; } .cs-kpi-rojo { border-top-color: #dc2626; }
    .cs-kpi-rotulo { font-size: 12px; color: var(--suave); text-transform: uppercase; letter-spacing: .5px; font-weight: 600; }
    .cs-kpi-valor { font-size: 28px; font-weight: 800; letter-spacing: -.5px; font-variant-numeric: tabular-nums; }
    .cs-kpi-valor small { font-size: 14px; font-weight: 600; color: var(--suave); }
    .cs-kpi-pie { font-size: 12px; color: var(--suave); }
    .cs-kpi:has(.cs-anillo) { padding-right: 72px; }
    .cs-anillo { position: absolute; right: 14px; top: 16px; width: 44px; height: 44px; border-radius: 50%;
                 background: conic-gradient(#059669 calc(var(--p) * 1%), #e2e8f0 0); }
    .cs-anillo::after { content: ''; position: absolute; inset: 7px; border-radius: 50%; background: var(--panel); }

    .cs-grilla { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr); gap: 18px; }
    @media (max-width: 1100px) { .cs-grilla { grid-template-columns: 1fr; } }
    .cs-leyenda { font-size: 12px; color: var(--suave); display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .cs-leyenda i { width: 10px; height: 10px; border-radius: 3px; display: inline-block; margin-left: 6px; }
    .l-verde, .b-verde { background: #10b981; } .l-ambar, .b-ambar { background: #f59e0b; } .l-rojo, .b-rojo { background: #ef4444; }

    /* Barras apiladas por hora */
    .cs-barras { display: grid; grid-template-columns: repeat(24, 1fr); gap: 4px; height: 180px; align-items: end; padding-top: 6px; }
    .cs-columna { height: 100%; display: flex; flex-direction: column; justify-content: flex-end; align-items: stretch; cursor: default; }
    .cs-pila { display: flex; flex-direction: column; border-radius: 4px 4px 2px 2px; overflow: hidden; min-height: 0;
               transition: filter .15s; animation: cs-crecer .6s ease-out both; transform-origin: bottom; }
    .cs-columna:hover .cs-pila { filter: brightness(1.1) saturate(1.2); }
    .cs-pila span { display: block; min-height: 2px; }
    .cs-hora { font-size: 10px; color: var(--suave); text-align: center; height: 14px; margin-top: 4px; }
    @keyframes cs-crecer { from { transform: scaleY(0); } to { transform: scaleY(1); } }
    @media (prefers-reduced-motion: reduce) { .cs-pila { animation: none; } }

    /* Salud por operacion */
    .cs-op { display: grid; grid-template-columns: 1fr auto; gap: 4px 10px; padding: 9px 0; border-bottom: 1px solid var(--borde);
             text-decoration: none; color: inherit; }
    .cs-op:last-child { border-bottom: 0; }
    .cs-op:hover .cs-op-nombre { color: var(--primario); }
    .cs-op-nombre { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .cs-op-datos { font-size: 12px; color: var(--suave); font-variant-numeric: tabular-nums; }
    .cs-op-barra { grid-column: 1 / -1; display: flex; height: 6px; border-radius: 999px; overflow: hidden; background: #f1f5f9; }

    /* Historial */
    .cs-tabla { overflow-x: auto; }
    .cs-fila { cursor: pointer; transition: background .1s; }
    .cs-fila:hover { background: #f8fafc; }
    .cs-cuando { white-space: nowrap; font-variant-numeric: tabular-nums; }
    .cs-cuando small, .cs-servicio { display: block; color: var(--suave); font-size: 11px; }
    .cs-empresa { max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cs-dijo { font-size: 13px; color: #334155; max-width: 520px; }
    .cs-ms { text-align: right; font-variant-numeric: tabular-nums; color: var(--suave); }
    .cs-ms.medio { color: #b45309; font-weight: 600; } .cs-ms.lento { color: #b91c1c; font-weight: 700; }
    .cs-ver { text-decoration: none; font-weight: 700; color: var(--primario); }

    /* Detalle: cabecera y navegacion */
    .cs-volver { font-size: 13px; color: var(--suave); text-decoration: none; }
    .cs-volver:hover { color: var(--primario); }
    .cs-meta { display: flex; gap: 6px 14px; flex-wrap: wrap; align-items: center; font-size: 13px; color: var(--suave); }
    .cs-nav { display: flex; gap: 8px; flex-wrap: wrap; }
    .cs-off { opacity: .4; pointer-events: none; }

    /* La conversacion */
    .cs-dialogo { display: grid; grid-template-columns: minmax(0, 1fr) 70px minmax(0, 1fr); align-items: stretch; margin-bottom: 18px; }
    .cs-dialogo .tarjeta { margin: 0; }
    .cs-flecha { display: flex; align-items: center; justify-content: center; position: relative; }
    .cs-flecha::before { content: ''; position: absolute; left: 4px; right: 10px; top: 50%; border-top: 2px dashed #94a3b8; }
    .cs-flecha::after { content: ''; position: absolute; right: 4px; top: calc(50% - 5px); border: 6px solid transparent;
                        border-left-color: #94a3b8; border-right: 0; }
    .cs-flecha span { position: relative; background: var(--bg); padding: 2px 6px; font-size: 11px; color: var(--suave);
                      border-radius: 999px; font-variant-numeric: tabular-nums; white-space: nowrap; }
    @media (max-width: 1000px) {
        .cs-dialogo { grid-template-columns: 1fr; gap: 10px; }
        .cs-flecha { height: 34px; }
        .cs-flecha::before { left: 50%; right: auto; top: 0; bottom: 8px; border-top: 0; border-left: 2px dashed #94a3b8; }
        .cs-flecha::after { right: auto; left: calc(50% - 5px); top: auto; bottom: 0; border: 6px solid transparent;
                            border-top-color: #94a3b8; border-bottom: 0; }
    }
    .cs-ida { border-left: 4px solid #3b82f6; }
    .cs-vuelta-verde { border-left: 4px solid #059669; } .cs-vuelta-ambar { border-left: 4px solid #d97706; } .cs-vuelta-rojo { border-left: 4px solid #dc2626; }

    .cs-campos { display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 6px 14px; margin: 0; font-size: 13px; }
    .cs-campos dt { color: var(--suave); }
    .cs-campos dd { margin: 0; font-family: ui-monospace, Menlo, Consolas, monospace; word-break: break-all; }
    .cs-campos dd.cs-nil { color: #94a3b8; font-style: italic; }

    .cs-veredicto { display: flex; gap: 12px; align-items: flex-start; }
    .cs-veredicto p { margin: 4px 0 0; font-size: 14px; color: #334155; }
    .cs-veredicto-icono { width: 38px; height: 38px; border-radius: 10px; display: grid; place-items: center; font-weight: 800; font-size: 18px; flex-shrink: 0; color: #fff; }
    .cs-vuelta-verde .cs-veredicto-icono { background: #059669; } .cs-vuelta-ambar .cs-veredicto-icono { background: #d97706; }
    .cs-vuelta-rojo .cs-veredicto-icono { background: #dc2626; }
    .cs-mensajes { list-style: none; padding: 0; margin: 12px 0 0; display: flex; flex-direction: column; gap: 6px; }
    .cs-mensajes li { font-size: 13px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 8px 10px; }
    .cs-codigo { display: inline-block; font-family: ui-monospace, Menlo, Consolas, monospace; font-weight: 700; background: #fde68a;
                 color: #92400e; border-radius: 6px; padding: 1px 7px; margin-right: 6px; }
    .cs-recepcion { font-size: 13px; color: var(--suave); margin: 12px 0 0; }
    .cs-nota { font-size: 13px; background: #eff6ff; border: 1px solid #bfdbfe; color: #1e3a8a; border-radius: 8px; padding: 10px 12px; margin: 12px 0 0; }

    /* Hash */
    .cs-hash { display: flex; gap: 14px; align-items: flex-start; }
    .cs-hash p { margin: 3px 0 6px; font-size: 13px; color: var(--suave); }
    .cs-hash-icono { width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; color: #fff; font-weight: 800; flex-shrink: 0; }
    .cs-hash-ok .cs-hash-icono { background: #059669; } .cs-hash-no .cs-hash-icono { background: #dc2626; }
    .cs-hash-no { border-color: #fecaca; background: #fff7f7; }
    .cs-hash-val { font-size: 12px; word-break: break-all; color: #334155; }

    /* Comparacion */
    .cs-comparar { display: flex; gap: 8px; align-items: center; }
    .cs-comparar input { width: 110px; }
    .cs-h3 { font-size: 13px; text-transform: uppercase; letter-spacing: .5px; color: var(--suave); margin: 18px 0 8px; }
    .cs-igual { color: #047857; font-weight: 600; font-size: 14px; margin: 0; }
    .cs-diff { background: #0b1220; color: #cbd5e1; border-radius: 10px; padding: 12px 0; overflow-x: auto; font-size: 12.5px; line-height: 1.55; margin: 0; }
    .cs-diff span { display: block; padding: 0 14px; white-space: pre; }
    .cs-diff .d-quitada { background: rgba(239,68,68,.16); color: #fecaca; }
    .cs-diff .d-agregada { background: rgba(16,185,129,.16); color: #a7f3d0; }
    .cs-diff .d-salto { color: #64748b; font-style: italic; }
    .d-quitada-txt { color: #b91c1c; font-weight: 600; } .d-agregada-txt { color: #047857; font-weight: 600; }

    /* Bloques de codigo */
    .cs-codigo-bloque { border: 1px solid var(--borde); border-radius: 10px; margin-top: 10px; overflow: hidden; }
    .cs-codigo-bloque summary { cursor: pointer; padding: 10px 14px; font-size: 13px; font-weight: 600; display: flex;
                                justify-content: space-between; align-items: center; background: #f8fafc; }
    .cs-copiar { background: var(--panel); border: 1px solid var(--borde); border-radius: 6px; padding: 4px 10px; font-size: 12px; cursor: pointer; }
    .cs-copiar:hover { border-color: var(--primario); color: var(--primario); }
    .cs-xml { background: #0b1220; color: #e2e8f0; margin: 0; padding: 14px; overflow: auto; max-height: 560px; font-size: 12.5px; line-height: 1.55; }
    .cs-xml .x-tag { color: #7dd3fc; } .cs-xml .x-attr { color: #c4b5fd; } .cs-xml .x-val { color: #fcd34d; }
</style>
