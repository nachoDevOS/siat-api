{{-- Campos compartidos por crear y editar empresa. --}}
@php($e = $empresa ?? null)

@if ($errors->any())
    <div class="error">
        @foreach ($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif

<div class="campo">
    <label>Nombre comercial</label>
    <input name="nombre_comercial" value="{{ old('nombre_comercial', $e?->nombre_comercial) }}" required>
</div>
<div class="campo">
    <label>Razon social</label>
    <input name="razon_social" value="{{ old('razon_social', $e?->razon_social) }}" required>
</div>
<div class="campo">
    <label>NIT</label>
    <input name="nit" value="{{ old('nit', $e?->nit) }}" required>
</div>
<div class="campo">
    <label>Codigo de sistema (lo asigna el SIN)</label>
    <input name="codigo_sistema" value="{{ old('codigo_sistema', $e?->codigo_sistema) }}">
</div>
<div class="campo">
    <label>Token delegado</label>
    <input name="token_delegado" value="{{ old('token_delegado', $e?->token_delegado) }}">
</div>
<div class="campo">
    <label>Ambiente</label>
    <select name="codigo_ambiente">
        <option value="2" @selected(old('codigo_ambiente', $e?->codigo_ambiente) == 2)>Piloto</option>
        <option value="1" @selected(old('codigo_ambiente', $e?->codigo_ambiente) == 1)>Produccion</option>
    </select>
</div>
<div class="campo">
    <label>Modalidad</label>
    <select name="codigo_modalidad">
        <option value="1" @selected(old('codigo_modalidad', $e?->codigo_modalidad) == 1)>Electronica</option>
        <option value="2" @selected(old('codigo_modalidad', $e?->codigo_modalidad) == 2)>Computarizada</option>
    </select>
</div>
<div class="campo">
    <label>Estado</label>
    <select name="estado">
        @foreach (['EN_REGISTRO','EN_PRUEBAS','PILOTO_APROBADO','PRODUCCION','OBSERVADO'] as $estado)
            <option value="{{ $estado }}" @selected(old('estado', $e?->estado) === $estado)>{{ $estado }}</option>
        @endforeach
    </select>
</div>
<div class="campo">
    <label>Webhook URL</label>
    <input name="webhook_url" value="{{ old('webhook_url', $e?->webhook_url) }}">
</div>
