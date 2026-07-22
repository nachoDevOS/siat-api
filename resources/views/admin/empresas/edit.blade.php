@extends('layouts.admin')
@section('titulo', 'Editar empresa')

@section('contenido')
    <h1>Editar {{ $empresa->nombre_comercial }}</h1>
    <div class="tarjeta">
        <form method="POST" action="{{ route('admin.empresas.update', $empresa) }}">
            @csrf
            @method('PUT')
            @include('admin.empresas._form')
            <button class="btn" type="submit">Guardar</button>
            <a class="btn gris" href="{{ route('admin.empresas.show', $empresa) }}">Cancelar</a>
        </form>
    </div>
@endsection
