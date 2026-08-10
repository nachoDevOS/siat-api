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
    {{-- Al dar de alta viene precargado con el del proveedor. Si el SIN emite
         uno propio para este contribuyente, se pisa aca. --}}
    <input name="codigo_sistema"
           value="{{ old('codigo_sistema', $e?->codigo_sistema ?? config('siat.proveedor.codigo_sistema')) }}">
    @unless ($e)
        <small style="color:var(--suave); font-size:12px;">
            Precargado con el codigo del proveedor. Cambialo si el SIN le asigno uno propio a este cliente.
        </small>
    @endunless
</div>
<div class="campo">
    <label>Token delegado</label>
    {{-- Nunca se devuelve al navegador: es la credencial con la que se firma
         cada llamada al SIN. En edicion el campo va vacio y solo se guarda si
         se escribe algo; dejarlo en blanco conserva el token actual. --}}
    <input name="token_delegado" type="password" autocomplete="off"
           value="{{ old('token_delegado') }}"
           placeholder="{{ $e && filled($e->token_delegado) ? 'Cargado — escribi uno nuevo solo si querés reemplazarlo' : '' }}">
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
