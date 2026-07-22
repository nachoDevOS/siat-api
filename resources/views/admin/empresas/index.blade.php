@extends('layouts.admin')
@section('titulo', 'Empresas')

@section('contenido')
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h1>Empresas</h1>
        <a class="btn" href="{{ route('admin.empresas.create') }}">Nueva empresa</a>
    </div>

    <div class="tarjeta">
        <table>
            <thead>
                <tr><th>Nombre</th><th>NIT</th><th>Ambiente</th><th>Estado</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($empresas as $empresa)
                    <tr>
                        <td>{{ $empresa->nombre_comercial }}</td>
                        <td>{{ $empresa->nit }}</td>
                        <td>{{ $empresa->codigo_ambiente === 1 ? 'Produccion' : 'Piloto' }}</td>
                        <td><span class="pill">{{ $empresa->estado }}</span></td>
                        <td><a href="{{ route('admin.empresas.show', $empresa) }}">Ver</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5">Sin empresas aun.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $empresas->links() }}
@endsection
