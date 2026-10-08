@extends('layouts.admin')
@section('titulo', 'Pruebas piloto')

@section('contenido')
    @php
        use App\Models\Empresa;
        use App\Models\EjecucionPrueba;

        use App\Models\CasoPrueba;

        // El bloqueo es por prueba: sin token no corre nada; sin certificado
        // solo se frenan las que firman (ver CasoPrueba::requiereCertificado).
        $puedeCorrer = fn (CasoPrueba $caso): bool => $requisitos['token']
            && (! $caso->requiereCertificado() || $requisitos['certificado']);
        $pilotoCompleto = $progreso['total'] > 0 && $progreso['exitosos'] === $progreso['total'];

        // Avance como lo calcula el portal: correctas sobre esperadas, sin
        // contar de mas lo que se repitio por encima de lo pedido.
        $avance = function ($casosEtapa) use ($correctas): array {
            $esperadas = $casosEtapa->sum('pruebas_esperadas');
            $hechas = $casosEtapa->sum(fn ($c) => min($correctas[$c->id] ?? 0, $c->pruebas_esperadas));

            return [$hechas, $esperadas, $esperadas === 0 ? 0 : (int) round($hechas / $esperadas * 100)];
        };
        $romanos = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI', 7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII'];
        [$hechasTotal, $esperadasTotal, $porcentajeTotal] = $avance($etapas->flatten());
        $primeraIncompleta = $etapas->keys()->first(function ($numero) use ($etapas, $avance) {
            [$hechas, $esperadas] = $avance($etapas[$numero]);

            return $hechas < $esperadas;
        });
    @endphp

    <div style="display:flex; justify-content:space-between; align-items:start; gap:12px; flex-wrap:wrap;">
        <div>
            <h1 style="margin:0 0 6px;">Piloto — {{ $empresa->nombre_comercial }}</h1>
            <x-estado-empresa :estado="$empresa->estado" />
        </div>
        <a class="btn gris" href="{{ route('admin.empresas.show', $empresa) }}">Volver a la ficha</a>
    </div>

    {{-- ---- Avance general de las etapas del portal ---- --}}
    <div class="tarjeta" style="margin-top:16px;">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
            <h2 style="margin-bottom:8px;">Porcentaje general: {{ $hechasTotal }}/{{ $esperadasTotal }} ({{ $porcentajeTotal }}%)</h2>
            <form method="POST" action="{{ route('admin.pruebas.limpiar', $empresa) }}" style="margin:0;"
                  onsubmit="return confirm('Borra el conteo local de TODAS las etapas y los jobs en cola. Lo que ya registro el SIN no se deshace. ¿Continuar?');">
                @csrf
                @method('DELETE')
                <button class="btn rojo" type="submit">Limpiar todas</button>
            </form>
        </div>
        <div class="progreso"><span style="width: {{ $porcentajeTotal }}%;"></span></div>

        <div style="display:flex; gap:18px; margin-top:14px; flex-wrap:wrap;">
            <x-semaforo titulo="Token delegado"
                        :color="$requisitos['token'] ? 'verde' : 'rojo'"
                        :texto="$requisitos['token'] ? 'cargado' : 'falta'" />
            <x-semaforo titulo="Certificado .p12"
                        :color="$requisitos['certificado'] ? 'verde' : 'rojo'"
                        :texto="$requisitos['certificado'] ? 'activo' : 'falta (solo lo piden las pruebas que firman)'" />
        </div>

        @unless ($requisitos['token'])
            <p class="error" style="margin-bottom:0;">Sin token delegado no corre ninguna prueba.</p>
        @endunless

        @if ($enCola > 0)
            <p class="aviso" style="margin:10px 0 0;">
                {{ $enCola }} prueba(s) en cola. Si este numero no baja al recargar, no hay worker:
                corre <code>php artisan queue:work</code>.
            </p>
        @endif

        <p style="font-size:12px; color:var(--suave); margin:10px 0 0;">
            El conteo es local: cuenta las solicitudes que salieron bien desde este sistema.
            El oficial es el del portal del SIN (Seguimiento de Autorizacion de Sistemas).
        </p>
    </div>

    {{-- ---- Una tarjeta por etapa, con las pruebas tal como las lista el portal ---- --}}
    @foreach ($etapas as $numero => $casosEtapa)
        @php
            [$hechas, $esperadas, $porcentaje] = $avance($casosEtapa);
            $etapaCorrible = $casosEtapa->every($puedeCorrer);
        @endphp
        {{-- Cada etapa es desplegable: abierta solo la primera que falta, para
             que la pantalla no sea una pared de 40 filas. El navegador recuerda
             cuales abrio el operador (ver script al final). --}}
        <details class="tarjeta etapa" data-etapa="{{ $numero }}" @if ($numero === $primeraIncompleta) open @endif>
            <summary style="display:flex; align-items:center; gap:16px; flex-wrap:wrap; cursor:pointer; list-style:none;">
                <div style="flex:1; min-width:240px;">
                    <h2 style="margin-bottom:8px;">
                        <span class="flecha-etapa" style="display:inline-block; transition:transform .15s;">▸</span>
                        Etapa {{ $romanos[$numero] ?? $numero }} — {{ CasoPrueba::ETAPAS[$numero] ?? 'Sin nombre' }}
                        <span style="font-weight:normal; color:var(--suave);">{{ $hechas }}/{{ $esperadas }}</span>
                    </h2>
                    <div class="progreso"><span style="width: {{ $porcentaje }}%;"></span></div>
                    {{-- Sin esto los botones quedaban grises sin decir por que. --}}
                    @unless ($etapaCorrible)
                        <div class="error" style="font-size:12px; margin:6px 0 0;">
                            @if (! $requisitos['token'])
                                Bloqueada: falta el token delegado de la empresa.
                            @else
                                Bloqueada: esta etapa firma facturas y la empresa no tiene certificado .p12 activo.
                                <a href="{{ route('admin.empresas.show', $empresa) }}">Cargarlo en la ficha</a>.
                            @endif
                        </div>
                    @endunless
                </div>
                <form method="POST" action="{{ route('admin.pruebas.etapa', [$empresa, $numero]) }}" style="margin:0;">
                    @csrf
                    <button class="btn" type="submit" @disabled(! $etapaCorrible || $hechas === $esperadas)>
                        {{ $hechas === $esperadas ? 'Etapa completa' : 'Ejecutar etapa' }}
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.pruebas.limpiar', $empresa) }}" style="margin:0;"
                      onsubmit="return confirm('Borra el conteo local de la etapa {{ $romanos[$numero] ?? $numero }} y sus jobs en cola. Lo que ya registro el SIN no se deshace. ¿Continuar?');">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="etapa" value="{{ $numero }}">
                    <button class="btn gris" type="submit">Limpiar</button>
                </form>
            </summary>

            <table style="margin-top:12px;">
                <thead>
                    <tr>
                        <th style="width:36px;">N°</th>
                        <th>Prueba y parametros</th>
                        <th style="width:90px;">Esperadas</th>
                        <th style="width:90px;">Correctas</th>
                        <th style="width:140px;">Avance</th>
                        <th style="width:110px;">Ultima</th>
                        <th style="width:90px;"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($casosEtapa as $caso)
                        @php
                            $ejecucion = $ultimas[$caso->id] ?? null;
                            $hechasCaso = $correctas[$caso->id] ?? 0;
                            $pctCaso = (int) round(min($hechasCaso, $caso->pruebas_esperadas) / max(1, $caso->pruebas_esperadas) * 100);
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
                                {{-- Los mismos parametros que muestra el portal en el desplegable. --}}
                                <div style="font-size:12px; color:var(--suave); font-family:monospace;">
                                    @foreach ((array) $caso->payload_ejemplo as $campo => $valor)
                                        <span style="margin-right:10px;"><strong>{{ $campo }}</strong> = {{ $valor }}</span>
                                    @endforeach
                                </div>
                                {{-- El motivo a la vista: es lo que dice que hacer despues. --}}
                                @if ($ejecucion?->estado === EjecucionPrueba::ESTADO_FALLIDO && filled(data_get($ejecucion->respuesta, 'error')))
                                    <div class="error" style="font-size:12px; margin:6px 0 0; overflow-wrap:anywhere;">
                                        {{ data_get($ejecucion->respuesta, 'error') }}
                                    </div>
                                @endif
                                @if ($ejecucion)
                                    <details>
                                        <summary style="cursor:pointer; font-size:12px; color:var(--suave);">Ver respuesta</summary>
                                        <pre style="background:#f8fafc; border:1px solid var(--borde); border-radius:8px;
                                                    padding:10px; font-size:12px; overflow:auto; max-height:240px; white-space:pre-wrap; overflow-wrap:anywhere;">{{ json_encode($ejecucion->respuesta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                @endif
                            </td>
                            <td>{{ $caso->pruebas_esperadas }}</td>
                            <td>{{ $hechasCaso }}</td>
                            <td>
                                <div class="progreso"><span style="width: {{ $pctCaso }}%;"></span></div>
                                <div style="font-size:12px; color:var(--suave);">{{ $pctCaso }}%</div>
                            </td>
                            <td>
                                <x-badge :color="$color" :texto="$ejecucion?->estado ?? 'PENDIENTE'" />
                            </td>
                            <td>
                                <form method="POST" action="{{ route('admin.pruebas.caso', [$empresa, $caso]) }}" style="margin:0;">
                                    @csrf
                                    <button class="btn gris" type="submit" @disabled(! $puedeCorrer($caso))>
                                        {{ $ejecucion ? 'Repetir' : 'Ejecutar' }}
                                    </button>
                                </form>

                                {{-- Las pruebas de muchas repeticiones (50 por catalogo) se
                                     completan por cola: no entran en un request. --}}
                                @php $faltanCaso = max(0, $caso->pruebas_esperadas - $hechasCaso); @endphp
                                @if ($caso->pruebas_esperadas > 1 && $faltanCaso > 0)
                                    <form method="POST" action="{{ route('admin.pruebas.completar', [$empresa, $caso]) }}" style="margin:6px 0 0;">
                                        @csrf
                                        <button class="btn" type="submit" @disabled(! $puedeCorrer($caso))>Completar ({{ $faltanCaso }})</button>
                                    </form>
                                @endif

                                {{-- El SIN devolvio el CUIS vigente (980): no emite otro hasta
                                     cerrar las operaciones del sistema en ese punto de venta. --}}
                                @if ($caso->tipo === 'solicitudCuis' && $ejecucion?->estado === EjecucionPrueba::ESTADO_FALLIDO
                                     && str_contains((string) data_get($ejecucion->respuesta, 'error'), 'ya estaba vigente'))
                                    <form method="POST" action="{{ route('admin.pruebas.cerrar-operaciones', [$empresa, $caso]) }}"
                                          style="margin:6px 0 0;"
                                          onsubmit="return confirm('Cierra las operaciones del sistema en este punto de venta ante el SIN (piloto). Despues hay que volver a ejecutar la prueba. ¿Continuar?');">
                                        @csrf
                                        <button class="btn rojo" type="submit" @disabled(! $puedeCorrer($caso))>Cerrar operaciones</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </details>
    @endforeach

    {{-- ---- Con todas las etapas completas se ofrece cerrar el piloto ----
         El cambio no es automatico: quien aprueba el piloto es el SIN, aca solo
         se refleja lo que ya paso afuera. --}}
    @if ($pilotoCompleto && $empresa->estado !== Empresa::ESTADO_PILOTO_APROBADO)
        <div class="aviso" style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
            <span>Las {{ $progreso['total'] }} pruebas del portal estan completas. ¿Marcar al cliente como piloto aprobado?</span>
            <form method="POST" action="{{ route('admin.empresas.estado', $empresa) }}" style="margin:0;">
                @csrf
                <input type="hidden" name="estado" value="{{ Empresa::ESTADO_PILOTO_APROBADO }}">
                <button class="btn" type="submit">Marcar PILOTO_APROBADO</button>
            </form>
        </div>
    @endif

    <style>
        details.etapa > summary::-webkit-details-marker { display: none; }
        details.etapa[open] .flecha-etapa { transform: rotate(90deg); }
    </style>
    <script>
        // Recuerda que etapas dejo abiertas el operador entre recargas. Es solo
        // comodidad: si el navegador no deja guardar, se usa lo de por defecto.
        (function () {
            var clave = 'piloto.etapas.{{ $empresa->id }}';
            var guardadas = null;
            try { guardadas = JSON.parse(localStorage.getItem(clave)); } catch (e) {}

            document.querySelectorAll('details.etapa').forEach(function (d) {
                if (Array.isArray(guardadas)) { d.open = guardadas.indexOf(d.dataset.etapa) !== -1; }
                d.addEventListener('toggle', function () {
                    var abiertas = Array.from(document.querySelectorAll('details.etapa[open]')).map(function (x) { return x.dataset.etapa; });
                    try { localStorage.setItem(clave, JSON.stringify(abiertas)); } catch (e) {}
                });
            });
        })();
    </script>
@endsection
