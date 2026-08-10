@extends('layouts.admin')
@section('titulo', 'Empresas')

@section('contenido')
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
        <h1 style="margin:0;">Clientes</h1>
        <a class="btn" href="{{ route('admin.empresas.create') }}">+ Nuevo cliente</a>
    </div>

    {{-- Filtro por estado y buscador. Van por GET para que la URL sea compartible. --}}
    <div class="tarjeta" style="margin-top:16px;">
        <form method="GET" action="{{ route('admin.empresas.index') }}" class="filtros">
            <div class="campo">
                <label for="q">Buscar</label>
                <input id="q" name="q" value="{{ $busqueda }}" placeholder="NIT, nombre o razon social">
            </div>
            <div class="campo">
                <label for="estado">Estado</label>
                <select id="estado" name="estado">
                    <option value="">Todos</option>
                    @foreach ($estadosDisponibles as $valor => $etiqueta)
                        <option value="{{ $valor }}" @selected($estado === $valor)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn" type="submit">Filtrar</button>
            @if ($busqueda !== '' || $estado !== '')
                <a class="btn gris" href="{{ route('admin.empresas.index') }}">Limpiar</a>
            @endif
        </form>
    </div>

    <div class="tarjeta">
        <table>
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>NIT</th>
                    <th>Ambiente</th>
                    <th>Estado</th>
                    <th style="min-width:180px;">Avance</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($empresas as $empresa)
                    <tr>
                        <td>
                            <div style="font-weight:600;">{{ $empresa->nombre_comercial }}</div>
                            <div style="font-size:12px; color:var(--suave);">{{ $empresa->razon_social }}</div>
                        </td>
                        <td>{{ $empresa->nit }}</td>
                        <td>
                            <span class="pill">{{ $empresa->codigo_ambiente === 1 ? 'Produccion' : 'Piloto' }}</span>
                        </td>
                        <td><x-estado-empresa :estado="$empresa->estado" /></td>
                        <td><x-stepper :estado="$empresa->estado" compacto /></td>
                        <td><a href="{{ route('admin.empresas.show', $empresa) }}">Ver ficha</a></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="color:var(--suave);">
                            @if ($busqueda !== '' || $estado !== '')
                                Ningun cliente coincide con el filtro.
                            @else
                                Sin clientes aun. Empeza dando de alta el primero.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $empresas->links() }}
@endsection
