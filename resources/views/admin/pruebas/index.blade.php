@extends('layouts.admin')
@section('titulo', 'Pruebas piloto')

@section('contenido')
    @php
        use App\Models\Empresa;
        use App\Models\EjecucionPrueba;

        $habilitado = $requisitos['token'] && $requisitos['certificado'];
        $pilotoCompleto = $progreso['total'] > 0 && $progreso['exitosos'] === $progreso['total'];
    @endphp

    <div style="display:flex; justify-content:space-between; align-items:start; gap:12px; flex-wrap:wrap;">
        <div>
            <h1 style="margin:0 0 6px;">Piloto — {{ $empresa->nombre_comercial }}</h1>
            <x-estado-empresa :estado="$empresa->estado" />
        </div>
        <a class="btn gris" href="{{ route('admin.empresas.show', $empresa) }}">Volver a la ficha</a>
    </div>

    {{-- ---- Progreso y ejecucion completa ---- --}}
    <div class="tarjeta" style="margin-top:16px;">
        <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
            <div style="flex:1; min-width:240px;">
                <h2 style="margin-bottom:8px;">Progreso: {{ $progreso['exitosos'] }}/{{ $progreso['total'] }}</h2>
                <div class="progreso"><span style="width: {{ $progreso['porcentaje'] }}%;"></span></div>
            </div>
            <form method="POST" action="{{ route('admin.pruebas.ejecutar', $empresa) }}">
                @csrf
                <button class="btn" type="submit" @disabled(! $habilitado)>Ejecutar todos en orden</button>
            </form>
        </div>

        {{-- Requisitos del portal del SIN: sin esto, todo paso falla por token. --}}
        <div style="display:flex; gap:18px; margin-top:14px; flex-wrap:wrap;">
            <x-semaforo titulo="Token delegado"
                        :color="$requisitos['token'] ? 'verde' : 'rojo'"
                        :texto="$requisitos['token'] ? 'cargado' : 'falta'" />
            <x-semaforo titulo="Certificado .p12"
                        :color="$requisitos['certificado'] ? 'verde' : 'rojo'"
                        :texto="$requisitos['certificado'] ? 'activo' : 'falta'" />
        </div>

        @unless ($habilitado)
            <p class="error" style="margin-bottom:0;">Complete token y certificado antes de iniciar.</p>
        @endunless
    </div>

    {{-- ---- Al completar el paso 17 se ofrece cerrar el piloto ----
         El cambio no es automatico: quien aprueba el piloto es el SIN, aca solo
         se refleja lo que ya paso afuera. --}}
    @if ($pilotoCompleto && $empresa->estado !== Empresa::ESTADO_PILOTO_APROBADO)
        <div class="aviso" style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
            <span>Los {{ $progreso['total'] }} pasos estan en EXITOSO. ¿Marcar al cliente como piloto aprobado?</span>
            <form method="POST" action="{{ route('admin.empresas.estado', $empresa) }}" style="margin:0;">
                @csrf
                <input type="hidden" name="estado" value="{{ Empresa::ESTADO_PILOTO_APROBADO }}">
                <button class="btn" type="submit">Marcar PILOTO_APROBADO</button>
            </form>
        </div>
    @endif

    {{-- ---- Los 17 pasos, cada uno con su estado, su respuesta y su boton ---- --}}
    <div class="tarjeta">
        <h2>Secuencia del piloto</h2>
        <table>
            <thead>
                <tr>
                    <th style="width:36px;">#</th>
                    <th>Caso</th>
                    <th style="width:110px;">Estado</th>
                    <th style="width:70px;">ms</th>
                    <th style="width:110px;">Ejecutado</th>
                    <th style="width:90px;"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($casos as $caso)
                    @php
                        $ejecucion = $ultimas[$caso->id] ?? null;
                        $color = match ($ejecucion?->estado) {
                            EjecucionPrueba::ESTADO_EXITOSO => 'verde',
                            EjecucionPrueba::ESTADO_FALLIDO => 'rojo',
                            null => 'gris',
                            default => 'azul',
                        };
                    @endphp
                    <tr>
                        <td>{{ $caso->orden }}</td>
                        <td>
                            <div>{{ $caso->nombre }}</div>
                            @if ($caso->descripcion)
                                <div style="font-size:12px; color:var(--suave);">{{ $caso->descripcion }}</div>
                            @endif

                            {{-- Los pasos que emiten documentos necesitan los datos
                                 de la especificacion que el SIN genera por cliente.
                                 Se cargan aca; el sistema no los inventa. --}}
                            <details>
                                <summary style="cursor:pointer; font-size:12px; color:var(--suave);">
                                    Datos del paso (payload)
                                    @if (filled($caso->payload_ejemplo))
                                        <span class="badge badge-verde">cargado</span>
                                    @endif
                                </summary>
                                <form method="POST" action="{{ route('admin.pruebas.payload', [$empresa, $caso]) }}"
                                      style="margin-top:8px;">
                                    @csrf
                                    <textarea name="payload_ejemplo" rows="6" placeholder="JSON con los datos que pide la especificacion del SIN"
                                              style="width:100%; font-family:monospace; font-size:12px; padding:8px;
                                                     border:1px solid #cbd5e1; border-radius:8px;">{{ filled($caso->payload_ejemplo) ? json_encode($caso->payload_ejemplo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '' }}</textarea>
                                    <button class="btn gris" type="submit" style="margin-top:6px;">Guardar payload</button>
                                </form>
                            </details>

                            @if ($ejecucion)
                                <details>
                                    <summary style="cursor:pointer; font-size:12px; color:var(--suave);">Ver respuesta</summary>
                                    <pre style="background:#f8fafc; border:1px solid var(--borde); border-radius:8px;
                                                padding:10px; font-size:12px; overflow:auto; max-height:240px;">{{ json_encode($ejecucion->respuesta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            @endif
                        </td>
                        <td>
                            <x-badge :color="$color" :texto="$ejecucion?->estado ?? 'PENDIENTE'" />
                        </td>
                        <td>{{ $ejecucion?->duracion_ms }}</td>
                        <td>{{ optional($ejecucion?->ejecutado_en)->format('d/m H:i') }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.pruebas.caso', [$empresa, $caso]) }}">
                                @csrf
                                <button class="btn gris" type="submit" @disabled(! $habilitado)>
                                    {{ $ejecucion ? 'Reintentar' : 'Ejecutar' }}
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
