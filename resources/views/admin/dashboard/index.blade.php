@extends('layouts.admin')
@section('titulo', 'Dashboard')

@section('contenido')
    <style>
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 16px; margin-bottom: 6px; }
        .stat { background: #fff; border: 1px solid var(--borde); border-radius: 12px; padding: 16px 18px;
                box-shadow: 0 1px 3px rgba(15,23,42,.04); }
        .stat .top { display: flex; justify-content: space-between; align-items: center; }
        .stat .rot { font-size: 13px; color: var(--suave); }
        .stat .ico { width: 38px; height: 38px; border-radius: 10px; display: grid; place-items: center; font-size: 18px; color: #fff; }
        .stat .num { font-size: 30px; font-weight: 800; margin-top: 8px; line-height: 1; }
        .stat .sub { font-size: 12px; color: var(--suave); margin-top: 6px; }
        .cols { display: grid; grid-template-columns: 1.4fr 1fr; gap: 18px; }
        @media (max-width: 900px) { .cols { grid-template-columns: 1fr; } }
        .barra { display: flex; align-items: center; gap: 10px; margin: 10px 0; }
        .barra .et { width: 110px; font-size: 13px; color: #334155; text-transform: capitalize; }
        .barra .track { flex: 1; height: 10px; background: #eef2f7; border-radius: 999px; overflow: hidden; }
        .barra .fill { height: 100%; border-radius: 999px; background: linear-gradient(90deg,#3b82f6,#8b5cf6); }
        .barra .val { width: 40px; text-align: right; font-size: 13px; font-weight: 600; }
        .donut { display: flex; align-items: center; gap: 18px; }
        .donut .anillo { width: 120px; height: 120px; border-radius: 50%; display: grid; place-items: center; flex-shrink: 0; }
        .donut .anillo .in { width: 84px; height: 84px; background: #fff; border-radius: 50%; display: grid; place-items: center;
                             font-size: 24px; font-weight: 800; }
        .vacio { color: var(--suave); font-size: 14px; padding: 8px 0; }
    </style>

    {{-- ---- Tarjetas de indicadores ---- --}}
    <div class="stats">
        <div class="stat">
            <div class="top"><span class="rot">Empresas</span><span class="ico" style="background:#2563eb;">▤</span></div>
            <div class="num">{{ $totalEmpresas }}</div>
            <div class="sub">{{ $empresasProduccion }} en produccion · {{ $empresasPiloto }} piloto</div>
        </div>
        <div class="stat">
            <div class="top"><span class="rot">Facturas</span><span class="ico" style="background:#8b5cf6;">🧾</span></div>
            <div class="num">{{ $totalFacturas }}</div>
            <div class="sub">emitidas en total</div>
        </div>
        <div class="stat">
            <div class="top"><span class="rot">Puntos de venta</span><span class="ico" style="background:#0891b2;">◆</span></div>
            <div class="num">{{ $totalPuntosVenta }}</div>
            <div class="sub">{{ $totalSucursales }} sucursales</div>
        </div>
        <div class="stat">
            <div class="top"><span class="rot">Peticiones SIAT</span><span class="ico" style="background:#059669;">⚡</span></div>
            <div class="num">{{ $totalPeticiones }}</div>
            <div class="sub">{{ $promedioMs }} ms promedio</div>
        </div>
    </div>

    <div class="cols">
        {{-- ---- Facturas por estado ---- --}}
        <div class="tarjeta">
            <h2>Facturas por estado</h2>
            @php($maxEstado = $facturasPorEstado->max() ?: 1)
            @forelse ($facturasPorEstado as $estado => $total)
                <div class="barra">
                    <span class="et">{{ $estado }}</span>
                    <span class="track"><span class="fill" style="width: {{ max(4, round($total / $maxEstado * 100)) }}%;"></span></span>
                    <span class="val">{{ $total }}</span>
                </div>
            @empty
                <p class="vacio">Sin facturas emitidas aun.</p>
            @endforelse
        </div>

        {{-- ---- Salud de la conexion SOAP ---- --}}
        <div class="tarjeta">
            <h2>Salud SIAT (SOAP)</h2>
            @php($grados = round($tasaExito * 3.6))
            <div class="donut">
                <div class="anillo" style="background: conic-gradient({{ $tasaExito >= 80 ? '#059669' : ($tasaExito >= 50 ? '#d97706' : '#dc2626') }} {{ $grados }}deg, #eef2f7 0);">
                    <div class="in">{{ $tasaExito }}%</div>
                </div>
                <div>
                    <div style="font-size:14px; color:#334155;">Tasa de exito</div>
                    <div class="sub" style="margin-top:6px;">{{ $totalPeticiones }} peticiones registradas</div>
                    <div class="sub">Latencia media: {{ $promedioMs }} ms</div>
                    <div class="sub">Casos de prueba: {{ $totalCasosPrueba }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="cols">
        {{-- ---- Ultimas facturas ---- --}}
        <div class="tarjeta">
            <h2>Ultimas facturas</h2>
            <table>
                <thead><tr><th>Nro</th><th>Empresa</th><th>Total</th><th>Estado</th><th>Fecha</th></tr></thead>
                <tbody>
                    @forelse ($ultimasFacturas as $factura)
                        <tr>
                            <td>{{ $factura->numero_factura }}</td>
                            <td>{{ $factura->empresa?->nombre_comercial ?? '—' }}</td>
                            <td>{{ number_format((float) $factura->monto_total, 2) }}</td>
                            <td><span class="pill">{{ $factura->estado }}</span></td>
                            <td>{{ optional($factura->fecha_emision)->format('d/m H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="vacio">Sin facturas aun.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- ---- Ultimas peticiones SOAP ---- --}}
        <div class="tarjeta">
            <h2>Ultimas peticiones SIAT</h2>
            <table>
                <thead><tr><th>Fecha</th><th>Operacion</th><th>ms</th><th>Ok</th></tr></thead>
                <tbody>
                    @forelse ($ultimasPeticiones as $log)
                        <tr>
                            <td>{{ optional($log->created_at)->format('d/m H:i:s') }}</td>
                            <td>{{ $log->operacion }}</td>
                            <td>{{ $log->duracion_ms }}</td>
                            <td><span class="{{ $log->exitoso ? 'ok' : 'no' }}">{{ $log->exitoso ? 'si' : 'no' }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="vacio">Sin peticiones registradas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
