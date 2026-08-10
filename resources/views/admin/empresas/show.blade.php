@extends('layouts.admin')
@section('titulo', $empresa->nombre_comercial)

@section('contenido')
    @php
        use App\Services\Panel\EstadosVisuales;
        use App\Services\Panel\RequisitosEtapa;

        $tieneCertificado = $certificado !== null;
        $tieneToken = filled($empresa->token_delegado);
        // El alta guiada habilita cada accion recien cuando la anterior esta
        // hecha, para no tener que adivinar el orden correcto.
        $puedeCorrerPiloto = $tieneToken && $tieneCertificado;
    @endphp

    <div style="display:flex; justify-content:space-between; align-items:start; gap:12px; flex-wrap:wrap;">
        <div>
            <h1 style="margin:0 0 6px;">{{ $empresa->nombre_comercial }}</h1>
            <div style="display:flex; gap:8px; align-items:center;">
                <x-estado-empresa :estado="$empresa->estado" />
                <span style="font-size:13px; color:var(--suave);">NIT {{ $empresa->nit }}</span>
            </div>
        </div>
        <div>
            <a class="btn gris" href="{{ route('admin.pruebas.show', $empresa) }}">Pruebas piloto</a>
            <a class="btn gris" href="{{ route('admin.empresas.edit', $empresa) }}">Editar</a>
        </div>
    </div>

    {{-- ---- Etapa del cliente y que le falta para avanzar ---- --}}
    {{-- Alta guiada: una sola frase con lo proximo que hay que hacer, para no
         tener que deducir el orden leyendo toda la ficha. --}}
    @php
        $pendiente = collect($requisitos['requisitos'])->firstWhere('cumplido', false);
    @endphp

    @if ($pendiente)
        <div class="tarjeta" style="margin-top:16px; border-left:4px solid var(--primario);">
            <div style="font-size:12px; color:var(--suave); text-transform:uppercase; letter-spacing:.6px;">
                Siguiente paso
            </div>
            <div style="font-size:16px; font-weight:600; margin-top:4px;">{{ $pendiente['titulo'] }}</div>
            <div style="font-size:13px; color:var(--suave); margin-top:2px;">{{ $pendiente['detalle'] }}</div>
        </div>
    @endif

    <div class="tarjeta" style="margin-top:16px;">
        <x-stepper :estado="$empresa->estado" style="margin-bottom:18px;" />

        @if ($requisitos['siguiente'])
            @php
                $siguienteEtiqueta = EstadosVisuales::empresa($requisitos['siguiente'])['etiqueta'];
            @endphp

            <h2>Para pasar a «{{ $siguienteEtiqueta }}» ({{ $requisitos['cumplidos'] }}/{{ $requisitos['total'] }})</h2>

            <ul class="chk">
                @foreach ($requisitos['requisitos'] as $requisito)
                    <li>
                        <span class="caja {{ $requisito['cumplido'] ? 'si' : 'nope' }}">{{ $requisito['cumplido'] ? '✓' : '✕' }}</span>
                        <span>
                            <span class="titulo">{{ $requisito['titulo'] }}</span>
                            <div class="detalle">{{ $requisito['detalle'] }}</div>
                        </span>
                    </li>
                @endforeach
            </ul>

            <form method="POST" action="{{ route('admin.empresas.estado', $empresa) }}" style="margin-top:14px;">
                @csrf
                <input type="hidden" name="estado" value="{{ $requisitos['siguiente'] }}">
                <button class="btn" type="submit" @disabled(! $requisitos['completos'])>
                    Marcar como {{ $siguienteEtiqueta }}
                </button>
                @unless ($requisitos['completos'])
                    <span style="font-size:13px; color:var(--suave); margin-left:8px;">
                        Faltan requisitos de la lista.
                    </span>
                @endunless
            </form>
        @else
            <p style="margin:0; color:var(--suave);">
                El cliente ya esta en produccion: puede facturar por la API.
            </p>
        @endif

        @if ($empresa->estado === App\Models\Empresa::ESTADO_OBSERVADO)
            <p style="margin-top:12px; color:#b91c1c; font-size:13px;">
                El SIN observo a este cliente. Corregi lo observado y volve a correr el piloto.
            </p>
        @endif
    </div>

    {{-- ---- Datos y credenciales ---- --}}
    <div class="tarjeta">
        <h2>Datos del contribuyente</h2>
        <table>
            <tr><th>Razon social</th><td>{{ $empresa->razon_social }}</td></tr>
            <tr><th>NIT</th><td>{{ $empresa->nit }}</td></tr>
            <tr><th>Codigo de sistema</th><td>{{ $empresa->codigo_sistema ?: '—' }}</td></tr>
            <tr><th>Ambiente</th><td>{{ $empresa->codigo_ambiente === 1 ? 'Produccion' : 'Piloto' }}</td></tr>
            <tr><th>Modalidad</th><td>{{ $empresa->codigo_modalidad === 1 ? 'Electronica' : 'Computarizada' }}</td></tr>
            <tr>
                <th>Token delegado</th>
                <td>
                    <x-semaforo :color="$tieneToken ? 'verde' : 'rojo'"
                                :texto="$tieneToken ? 'cargado' : 'sin cargar: no se puede hablar con el SIAT'" />
                </td>
            </tr>
            <tr><th>Webhook</th><td>{{ $empresa->webhook_url ?: '—' }}</td></tr>
        </table>
    </div>

    {{-- ---- Certificado digital ---- --}}
    <div class="tarjeta">
        <h2>Certificado digital (.p12)</h2>
        <p style="margin-top:0;">
            <x-semaforo :color="$semaforoCertificado['color']" :texto="$semaforoCertificado['texto']" />
            @if ($certificado?->vence_el)
                <span style="font-size:13px; color:var(--suave);">
                    ({{ $certificado->vence_el->format('d/m/Y') }})
                </span>
            @endif
        </p>

        <details @if (! $tieneCertificado) open @endif>
            <summary style="cursor:pointer; font-size:13px;">
                {{ $tieneCertificado ? 'Reemplazar certificado' : 'Cargar certificado' }}
            </summary>
            <form method="POST" action="{{ route('admin.empresas.certificados.store', $empresa) }}"
                  enctype="multipart/form-data" style="margin-top:10px;">
                @csrf
                <div class="campo"><label>Archivo .p12</label><input type="file" name="archivo" required></div>
                <div class="campo"><label>Passphrase</label><input type="password" name="passphrase" required></div>
                <div class="campo"><label>Vence el</label><input type="date" name="vence_el"></div>
                <button class="btn" type="submit">Cargar certificado</button>
            </form>
        </details>
    </div>

    {{-- ---- Estructura fisica y codigos del SIN ---- --}}
    <div class="tarjeta">
        <h2>Sucursales y puntos de venta</h2>

        @forelse ($empresa->sucursales as $sucursal)
            <div style="border-top:1px solid var(--borde); padding:12px 0;">
                <strong>Sucursal {{ $sucursal->codigo_sucursal }}</strong> — {{ $sucursal->nombre }}
                @if ($sucursal->codigo_sucursal === 0)
                    <span class="pill">casa matriz</span>
                @endif

                @foreach ($sucursal->puntosVenta as $pv)
                    @php
                        $cuis = $pv->cuisVigente();
                        $cufd = $pv->cufdVigente();
                        $cafc = $pv->cafcs()->where('fecha_vigencia', '>', now())->latest('fecha_vigencia')->first();

                        // El CUFD dura 24 h: se avisa con 2 h de anticipacion,
                        // igual que el cron que los renueva.
                        $semCuis = RequisitosEtapa::vigencia($cuis?->fecha_vigencia, 24 * 15);
                        $semCufd = RequisitosEtapa::vigencia($cufd?->fecha_vigencia, 2);
                        $semCafc = RequisitosEtapa::vigencia($cafc?->fecha_vigencia, 24 * 7);
                    @endphp

                    <div style="background:#f8fafc; border:1px solid var(--borde); border-radius:10px; padding:12px; margin:10px 0;">
                        <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                            <div>
                                <strong>PV {{ $pv->codigo_punto_venta }}</strong> — {{ $pv->nombre }}
                                @unless ($pv->activo)
                                    <x-badge color="rojo" texto="dado de baja" />
                                @endunless
                                {{-- Registrar en el SIAT es irreversible: se marca claro
                                     cuales ya existen del otro lado y cuales no. --}}
                                @if ($pv->estaRegistradoEnSiat())
                                    <x-badge color="verde" texto="registrado en el SIAT" />
                                @else
                                    <x-badge color="gris" texto="solo local" />
                                @endif
                            </div>
                            <span style="font-size:13px; color:var(--suave);">
                                proxima factura: {{ $pv->siguiente_factura }}
                            </span>
                        </div>

                        {{-- Semaforos de los tres codigos. Sin CUFD vigente no se emite. --}}
                        <div style="display:flex; gap:18px; flex-wrap:wrap; margin:10px 0;">
                            <x-semaforo titulo="CUIS" :color="$semCuis['color']" :texto="$semCuis['texto']" />
                            <x-semaforo titulo="CUFD" :color="$semCufd['color']" :texto="$semCufd['texto']" />
                            <x-semaforo titulo="CAFC" :color="$semCafc['color']" :texto="$semCafc['texto']" />
                        </div>

                        {{-- Solicitud al SIAT. El CUFD y el CAFC exigen CUIS: el
                             boton queda deshabilitado hasta tenerlo. --}}
                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                            <form method="POST" action="{{ route('admin.codigos.cuis', $pv) }}">@csrf
                                <button class="btn gris" type="submit">Solicitar CUIS</button>
                            </form>
                            <form method="POST" action="{{ route('admin.codigos.cufd', $pv) }}">@csrf
                                <button class="btn gris" type="submit" @disabled($cuis === null)
                                        title="{{ $cuis === null ? 'Primero hace falta un CUIS vigente' : '' }}">
                                    Solicitar CUFD
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.codigos.cafc', $pv) }}">@csrf
                                <button class="btn gris" type="submit" @disabled($cuis === null)
                                        title="{{ $cuis === null ? 'Primero hace falta un CUIS vigente' : '' }}">
                                    Solicitar CAFC
                                </button>
                            </form>
                        </div>

                        {{-- Carga manual: para probar el sistema sin conexion al SIN. --}}
                        <details style="margin-top:10px;">
                            <summary style="cursor:pointer; font-size:13px;">Carga manual (pruebas sin SIAT)</summary>
                            <form method="POST" action="{{ route('admin.codigos.cuis.manual', $pv) }}" style="display:flex; gap:6px; align-items:end; margin-top:8px;">
                                @csrf
                                <div class="campo" style="margin:0;"><label>CUIS codigo</label><input name="codigo" required></div>
                                <button class="btn" type="submit">+ CUIS</button>
                            </form>
                            <form method="POST" action="{{ route('admin.codigos.cufd.manual', $pv) }}" style="display:flex; gap:6px; align-items:end; margin-top:8px;">
                                @csrf
                                <div class="campo" style="margin:0;"><label>CUFD codigo</label><input name="codigo" required></div>
                                <div class="campo" style="margin:0;"><label>codigo_control</label><input name="codigo_control" required></div>
                                <button class="btn" type="submit">+ CUFD</button>
                            </form>
                            <form method="POST" action="{{ route('admin.codigos.cafc.manual', $pv) }}" style="display:flex; gap:6px; align-items:end; margin-top:8px;">
                                @csrf
                                <div class="campo" style="margin:0;"><label>CAFC codigo</label><input name="codigo" required></div>
                                <div class="campo" style="margin:0;"><label>cant. facturas</label><input name="cantidad_facturas" type="number" value="1000" required></div>
                                <button class="btn" type="submit">+ CAFC</button>
                            </form>
                        </details>
                    </div>
                @endforeach

                {{-- Puntos de venta que YA existen en el SIAT. Se consultan a pedido
                     porque registrar uno nuevo es irreversible: conviene mirar que hay
                     del otro lado antes de crear otro duplicado. --}}
                @php($siat = session('puntos_venta_siat'))

                @if ($siat && $siat['sucursal_id'] === $sucursal->id)
                    <div style="background:#f1f5f9; border:1px solid var(--borde); border-radius:10px; padding:12px; margin:10px 0;">
                        <strong style="font-size:14px;">Puntos de venta registrados en el SIAT</strong>
                        @forelse ($siat['lista'] as $remoto)
                            <div style="display:flex; gap:10px; align-items:center; justify-content:space-between; padding:6px 0; border-bottom:1px solid var(--borde); flex-wrap:wrap;">
                                <span style="font-size:13px;">
                                    <strong>{{ $remoto['codigo'] }}</strong> — {{ $remoto['nombre'] }}
                                    <span style="color:var(--suave);">({{ $remoto['tipo'] }})</span>
                                </span>
                                @foreach ($sucursal->puntosVenta as $local)
                                    <form method="POST" action="{{ route('admin.puntos-venta.adoptar-codigo', $local) }}" style="margin:0;">
                                        @csrf
                                        <input type="hidden" name="codigo_punto_venta" value="{{ $remoto['codigo'] }}">
                                        <button class="btn" type="submit" style="font-size:12px;">
                                            Usar este codigo en «{{ $local->nombre }}»
                                        </button>
                                    </form>
                                @endforeach
                            </div>
                        @empty
                            <p style="color:var(--suave); font-size:13px; margin:6px 0 0;">
                                El SIAT no tiene ningun punto de venta en esta sucursal todavia.
                            </p>
                        @endforelse
                    </div>
                @endif

                <div style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;">
                    <form method="POST" action="{{ route('admin.sucursales.puntos-venta.store', $sucursal) }}" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;">
                        @csrf
                        <div class="campo" style="margin:0;"><label>Codigo PV</label><input name="codigo_punto_venta" type="number" value="0" required></div>
                        <div class="campo" style="margin:0;"><label>Nombre</label><input name="nombre" required></div>
                        <div class="campo" style="margin:0;">
                            <label>Tipo</label>
                            <select name="tipo_punto_venta" required>
                                {{-- Codigos reales del SIN, del catalogo sincronizado. --}}
                                @forelse ($tiposPuntoVenta as $tipo)
                                    <option value="{{ $tipo->codigo_clasificador }}">
                                        {{ $tipo->codigo_clasificador }} — {{ $tipo->descripcion }}
                                    </option>
                                @empty
                                    <option value="1">1 (sincroniza catalogos para ver la lista real)</option>
                                @endforelse
                            </select>
                        </div>
                        <button class="btn" type="submit">+ Punto de venta (local)</button>
                    </form>

                    <form method="POST" action="{{ route('admin.sucursales.puntos-venta.consultar', $sucursal) }}" style="margin:0;">
                        @csrf
                        <button class="btn" type="submit">Consultar los del SIAT</button>
                    </form>
                </div>
            </div>
        @empty
            <p style="color:var(--suave);">
                Sin sucursales. El primer paso es registrar la casa matriz (codigo 0).
            </p>
        @endforelse

        <h2 style="margin-top:18px;">Nueva sucursal</h2>
        <form method="POST" action="{{ route('admin.empresas.sucursales.store', $empresa) }}" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;">
            @csrf
            <div class="campo" style="margin:0;"><label>Codigo</label><input name="codigo_sucursal" type="number" value="0" required></div>
            <div class="campo" style="margin:0;"><label>Nombre</label><input name="nombre" value="Casa Matriz" required></div>
            <div class="campo" style="margin:0;"><label>Municipio</label><input name="municipio"></div>
            <button class="btn" type="submit">+ Sucursal</button>
        </form>
    </div>

    {{-- ---- Piloto ---- --}}
    <div class="tarjeta">
        <h2>Piloto del SIN</h2>
        <div style="display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
            <div style="flex:1; min-width:220px;">
                <div class="progreso"><span style="width: {{ $progresoPiloto['porcentaje'] }}%;"></span></div>
                <div style="font-size:13px; color:var(--suave); margin-top:6px;">
                    {{ $progresoPiloto['exitosos'] }}/{{ $progresoPiloto['total'] }} pasos superados
                </div>
            </div>
            <a class="btn" href="{{ route('admin.pruebas.show', $empresa) }}"
               style="{{ $puedeCorrerPiloto ? '' : 'background:#cbd5e1; pointer-events:none;' }}">
                Ir al piloto
            </a>
        </div>
        @unless ($puedeCorrerPiloto)
            <p style="font-size:13px; color:var(--suave); margin-bottom:0;">
                Cargá el token delegado y el certificado antes de correr el piloto.
            </p>
        @endunless
    </div>

    <form method="POST" action="{{ route('admin.empresas.destroy', $empresa) }}"
          onsubmit="return confirm('Eliminar esta empresa y todos sus datos?')">
        @csrf
        @method('DELETE')
        <button class="btn rojo" type="submit">Eliminar empresa</button>
    </form>
@endsection
