@extends('layouts.admin')
@section('titulo', 'Llamada #'.$log->id.' · '.$log->operacion)

@php
    use App\Services\Panel\LecturaLogSiat;

    $resultado = $lectura->resultado();
    $visual = LecturaLogSiat::VISUAL[$resultado];
    $respuesta = $lectura->respuesta();
    $parametros = $lectura->parametros();
    $camposRespuesta = $lectura->camposRespuesta();
    $documentos = $lectura->documentos();
    $hash = $lectura->hash();
@endphp

@push('cabecera')
    @include('admin.comunicacion._estilos')
@endpush

@section('contenido')
    <div class="cs-cabeza">
        <div>
            <a href="{{ route('admin.comunicacion.index') }}" class="cs-volver">← Comunicacion SIAT</a>
            <h1 style="margin:6px 0;"><span class="cs-mono">{{ $log->operacion }}</span></h1>
            <div class="cs-meta">
                <x-badge :color="$visual['color']" :texto="$visual['texto']" />
                <span>#{{ $log->id }}</span>
                <span>{{ $log->servicio }}</span>
                <span>{{ $log->empresa?->nombre_comercial ?? 'Sin empresa' }}</span>
                <span>{{ $log->created_at?->format('d/m/Y H:i:s') }} ({{ $log->created_at?->diffForHumans() }})</span>
                <span>{{ number_format((int) $log->duracion_ms) }} ms</span>
            </div>
        </div>
        <div class="cs-nav">
            @if ($factura)
                <a class="btn" href="{{ route('admin.facturas.show', $factura) }}">Ver factura N° {{ $factura->numero_factura }}</a>
            @endif
            <a class="btn gris {{ $anterior ? '' : 'cs-off' }}" href="{{ $anterior ? route('admin.comunicacion.show', $anterior) : '#' }}">‹ Anterior</a>
            <a class="btn gris {{ $siguiente ? '' : 'cs-off' }}" href="{{ $siguiente ? route('admin.comunicacion.show', $siguiente) : '#' }}">Siguiente ›</a>
        </div>
    </div>

    {{-- ---- La conversacion: lo que fue y lo que volvio ---- --}}
    <div class="cs-dialogo">
        <div class="tarjeta cs-ida">
            <div class="cs-titulo">
                <h2>↗ Lo que mando tu API</h2>
                <span class="cs-sub">{{ count($parametros) }} campos</span>
            </div>
            @if ($parametros === [])
                <p class="cs-vacio">No se pudo leer la solicitud.</p>
            @else
                <dl class="cs-campos">
                    @foreach ($parametros as $campo => $valor)
                        <dt>{{ $campo }}</dt>
                        <dd class="{{ $valor === '(nil)' ? 'cs-nil' : '' }}">{{ $valor }}</dd>
                    @endforeach
                </dl>
            @endif
        </div>

        <div class="cs-flecha" aria-hidden="true">
            <span>{{ number_format((int) $log->duracion_ms) }} ms</span>
        </div>

        <div class="tarjeta cs-vuelta cs-vuelta-{{ $visual['color'] }}">
            <div class="cs-titulo">
                <h2>↙ Lo que respondio el SIN</h2>
            </div>

            @if ($resultado === LecturaLogSiat::SIN_RESPUESTA)
                <div class="cs-veredicto">
                    <span class="cs-veredicto-icono">⚡</span>
                    <div>
                        <strong>No hubo respuesta</strong>
                        <p>{{ $log->mensaje_error ?: 'La llamada no termino.' }}</p>
                    </div>
                </div>
            @else
                <div class="cs-veredicto">
                    <span class="cs-veredicto-icono">{{ $resultado === LecturaLogSiat::ACEPTADA ? '✓' : '✕' }}</span>
                    <div>
                        <strong>
                            transaccion = {{ $respuesta['transaccion'] === null ? '(no vino)' : ($respuesta['transaccion'] ? 'true' : 'false') }}
                            @if ($respuesta['codigoEstado'] !== null)
                                · estado {{ $respuesta['codigoEstado'] }}
                            @endif
                        </strong>
                        @if ($respuesta['descripcion'])
                            <p>{{ $respuesta['descripcion'] }}</p>
                        @endif
                    </div>
                </div>

                @if ($respuesta['mensajes'] !== [])
                    <ul class="cs-mensajes">
                        @foreach ($respuesta['mensajes'] as $mensaje)
                            <li><span class="cs-codigo">{{ $mensaje['codigo'] }}</span> {{ $mensaje['descripcion'] }}</li>
                        @endforeach
                    </ul>
                @endif

                @if ($respuesta['codigoRecepcion'])
                    <p class="cs-recepcion">Codigo de recepcion <span class="cs-mono">{{ $respuesta['codigoRecepcion'] }}</span></p>
                @endif

                {{-- Un -1 sin mensajes es una excepcion interna del SIN, no una
                     regla incumplida: se aclara porque confunde. --}}
                @if ($respuesta['codigoEstado'] === '-1' && $respuesta['mensajes'] === [])
                    <p class="cs-nota">
                        <strong>Estado -1 sin mensajes:</strong> el SIN tuvo una excepcion interna. No indica un error
                        concreto del documento. Compara abajo con la ultima llamada aceptada: si no hay diferencias de
                        fondo, el problema esta del lado del SIN.
                    </p>
                @endif

                @if ($camposRespuesta !== [])
                    <dl class="cs-campos" style="margin-top:12px;">
                        @foreach ($camposRespuesta as $campo => $valor)
                            <dt>{{ $campo }}</dt><dd>{{ $valor }}</dd>
                        @endforeach
                    </dl>
                @endif
            @endif
        </div>
    </div>

    {{-- ---- Integridad del adjunto ---- --}}
    @if ($hash)
        <div class="tarjeta cs-hash {{ $hash['coincide'] ? 'cs-hash-ok' : 'cs-hash-no' }}">
            <span class="cs-hash-icono">{{ $hash['coincide'] ? '✓' : '✕' }}</span>
            <div>
                <strong>{{ $hash['coincide'] ? 'El hash del adjunto coincide' : 'El hash del adjunto NO coincide' }}</strong>
                <p>SHA-256 del gzip que viajo, recalculado aca y comparado con el <span class="cs-mono">hashArchivo</span> declarado.</p>
                <div class="cs-mono cs-hash-val">{{ $hash['calculado'] }}</div>
            </div>
        </div>
    @endif

    {{-- ---- Comparacion con la ultima aceptada ---- --}}
    <div class="tarjeta">
        <div class="cs-titulo">
            <h2>Comparacion</h2>
            <form method="GET" action="{{ route('admin.comunicacion.show', $log) }}" class="cs-comparar">
                <label for="comparar" class="cs-sub" style="margin:0;">Comparar con la llamada #</label>
                <input id="comparar" name="comparar" type="number" min="1" value="{{ $comparacion['log']->id ?? '' }}" placeholder="id">
                <button class="btn gris" type="submit">Comparar</button>
            </form>
        </div>

        @if ($comparacion === null)
            <p class="cs-vacio">
                {{ $resultado === LecturaLogSiat::ACEPTADA
                    ? 'Esta llamada fue aceptada. Escribe el id de otra para compararlas.'
                    : 'No hay una llamada aceptada anterior de esta operacion y empresa para comparar.' }}
            </p>
        @else
            @php($referencia = $comparacion['log'])
            @php($sinCambios = fn (array $lineas) => collect($lineas)->every(fn ($l) => in_array($l['tipo'], ['igual', 'salto'], true)))
            <p class="cs-sub" style="margin-top:0;">
                Contra
                <a href="{{ route('admin.comunicacion.show', $referencia) }}">#{{ $referencia->id }}</a>
                del {{ $referencia->created_at?->format('d/m/Y H:i:s') }}
                (<x-badge :color="LecturaLogSiat::VISUAL[(new LecturaLogSiat($referencia))->resultado()]['color']" :texto="LecturaLogSiat::VISUAL[(new LecturaLogSiat($referencia))->resultado()]['texto']" />).
                En <span class="d-quitada-txt">rojo</span> lo de aquella, en <span class="d-agregada-txt">verde</span> lo de esta. La firma va acortada: sus valores cambian siempre.
            </p>

            @foreach (['parametros' => 'Campos de la solicitud', 'documento' => 'Documento enviado'] as $parte => $rotulo)
                <h3 class="cs-h3">{{ $rotulo }}</h3>
                @if ($comparacion[$parte] === [])
                    <p class="cs-vacio">Ninguna de las dos llamadas trae {{ $parte === 'documento' ? 'un documento adjunto' : 'campos legibles' }}.</p>
                @elseif ($sinCambios($comparacion[$parte]))
                    <p class="cs-igual">✓ Identicos</p>
                @else
                    <pre class="cs-diff">@foreach ($comparacion[$parte] as $linea)<span class="d-{{ $linea['tipo'] }}">{{ ['igual' => '  ', 'quitada' => '− ', 'agregada' => '+ ', 'salto' => '⋯ '][$linea['tipo']] }}{{ $linea['texto'] }}</span>@endforeach</pre>
                @endif
            @endforeach
        @endif
    </div>

    {{-- ---- Documento que viajo dentro del <archivo> ---- --}}
    @if ($documentos !== [])
        <div class="tarjeta">
            <div class="cs-titulo">
                <h2>{{ count($documentos) === 1 ? 'Documento enviado' : 'Documentos del paquete ('.count($documentos).')' }}</h2>
                <span class="cs-sub">descomprimido del &lt;archivo&gt; (gzip + base64)</span>
            </div>
            @foreach (array_slice($documentos, 0, 20) as $i => $documento)
                @php($legible = LecturaLogSiat::legible($documento['xml']))
                <details class="cs-codigo-bloque" @if ($i === 0) open @endif>
                    <summary>
                        <span class="cs-mono">{{ $documento['nombre'] }}</span>
                        <button type="button" class="cs-copiar" data-copiar="doc-{{ $i }}">Copiar</button>
                    </summary>
                    <pre id="doc-{{ $i }}" class="cs-xml">{{ LecturaLogSiat::resaltar($legible) }}</pre>
                </details>
            @endforeach
            @if (count($documentos) > 20)
                <p class="cs-sub">Se muestran los primeros 20 de {{ count($documentos) }}.</p>
            @endif
        </div>
    @endif

    {{-- ---- Sobres SOAP tal cual ---- --}}
    <div class="tarjeta">
        <h2>Sobres SOAP tal cual</h2>
        @foreach (['enviado' => ['Solicitud', $log->xml_enviado], 'recibido' => ['Respuesta', $log->xml_recibido]] as $clave => [$rotulo, $xml])
            <details class="cs-codigo-bloque">
                <summary>
                    {{ $rotulo }}
                    @if (filled($xml))
                        <button type="button" class="cs-copiar" data-copiar="soap-crudo-{{ $clave }}">Copiar completo</button>
                    @endif
                </summary>
                @if (filled($xml))
                    {{-- En pantalla va acortado; se copia entero, como lo pide el SIN en un ticket. --}}
                    <textarea id="soap-crudo-{{ $clave }}" hidden>{{ $xml }}</textarea>
                    <pre class="cs-xml">{{ LecturaLogSiat::resaltar(LecturaLogSiat::legible($xml, acortarFirma: true)) }}</pre>
                @else
                    <p class="cs-vacio">No se guardo (sin traza o sin respuesta).</p>
                @endif
            </details>
        @endforeach
    </div>

    <script>
        // Copia el texto del bloque, no el HTML resaltado.
        document.querySelectorAll('[data-copiar]').forEach((boton) => {
            boton.addEventListener('click', (evento) => {
                evento.preventDefault();
                const origen = document.getElementById(boton.dataset.copiar);
                navigator.clipboard.writeText(origen.value ?? origen.innerText).then(() => {
                    const antes = boton.textContent;
                    boton.textContent = 'Copiado ✓';
                    setTimeout(() => (boton.textContent = antes), 1500);
                });
            });
        });
    </script>
@endsection
