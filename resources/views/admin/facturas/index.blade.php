@extends('layouts.admin')
@section('titulo', 'Facturas')

@section('contenido')
    <h1 style="margin:0;">Facturas</h1>

    {{-- Filtros por GET: la URL se puede compartir con quien da soporte. --}}
    <div class="tarjeta" style="margin-top:16px;">
        <form method="GET" action="{{ route('admin.facturas.index') }}" class="filtros">
            <div class="campo">
                <label for="q">Buscar</label>
                <input id="q" name="q" value="{{ $filtros['q'] }}" placeholder="CUF, referencia o N° de factura">
            </div>
            <div class="campo">
                <label for="empresa">Empresa</label>
                <select id="empresa" name="empresa">
                    <option value="">Todas</option>
                    @foreach ($empresas as $empresa)
                        <option value="{{ $empresa->id }}" @selected((int) ($filtros['empresa'] ?? 0) === $empresa->id)>{{ $empresa->nombre_comercial }}</option>
                    @endforeach
                </select>
            </div>
            <div class="campo">
                <label for="estado">Estado</label>
                <select id="estado" name="estado">
                    <option value="">Todos</option>
                    @foreach ($estados as $valor => $etiqueta)
                        <option value="{{ $valor }}" @selected(($filtros['estado'] ?? '') === $valor)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
            </div>
            <div class="campo">
                <label for="desde">Desde</label>
                <input type="date" id="desde" name="desde" value="{{ $filtros['desde'] ?? '' }}">
            </div>
            <div class="campo">
                <label for="hasta">Hasta</label>
                <input type="date" id="hasta" name="hasta" value="{{ $filtros['hasta'] ?? '' }}">
            </div>
            <button class="btn" type="submit">Filtrar</button>
            {{-- Por defecto se ve el mes actual; "Ver todo" manda las fechas vacias. --}}
            <a class="btn gris" href="{{ route('admin.facturas.index') }}">Este mes</a>
            <a class="btn gris" href="{{ route('admin.facturas.index', ['desde' => '', 'hasta' => '']) }}">Ver todo</a>
        </form>
    </div>

    <div class="tarjeta">
        <table>
            <thead>
                <tr><th>Fecha</th><th>Empresa</th><th>N°</th><th>Suc. / PV</th><th>Comprador</th><th class="derecha">Total</th><th>Estado</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($facturas as $factura)
                    <tr>
                        <td>{{ $factura->fecha_emision?->format('d/m/Y H:i') }}</td>
                        <td>{{ $factura->empresa->nombre_comercial }}</td>
                        <td>{{ $factura->numero_factura }}</td>
                        <td>{{ $factura->puntoVenta->sucursal->codigo_sucursal }} / {{ $factura->puntoVenta->codigo_punto_venta }}</td>
                        <td>{{ $factura->comprador_razon_social }}</td>
                        <td style="text-align:right;">{{ number_format((float) $factura->monto_total, 2) }}</td>
                        <td>
                            <x-estado-factura :estado="$factura->estado" />
                            @if ($factura->motivo_observacion)
                                <div style="font-size:12px; color:#b91c1c; margin-top:3px;">{{ \Illuminate\Support\Str::limit($factura->motivo_observacion, 60) }}</div>
                            @endif
                        </td>
                        <td><a href="{{ route('admin.facturas.show', $factura) }}">Ver</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" style="color:var(--suave);">No hay facturas con esos filtros.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div style="margin-top:12px;">{{ $facturas->links() }}</div>
    </div>
@endsection
