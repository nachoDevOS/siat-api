@extends('layouts.admin')
@section('titulo', 'Nueva empresa')

@section('contenido')
    <h1>Nueva empresa</h1>
    <div class="tarjeta">
        <form method="POST" action="{{ route('admin.empresas.store') }}">
            @csrf
            @include('admin.empresas._form')
            <button class="btn" type="submit">Crear</button>
            <a class="btn gris" href="{{ route('admin.empresas.index') }}">Cancelar</a>
        </form>
    </div>
@endsection
