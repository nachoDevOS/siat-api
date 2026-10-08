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

    {{-- La ficha va a dos columnas: a la izquierda QUIEN es el cliente (datos
         que casi no cambian), a la derecha QUE hay que hacer con el. Antes
         estaba todo apilado y los datos quedaban sepultados entre las
         acciones. --}}
    <div class="ficha" style="margin-top:16px;">

        {{-- ================= LATERAL: identidad del cliente ================= --}}
        <aside class="lateral">
        {{-- ---- Datos y credenciales ---- --}}
        <div class="tarjeta">
            <h2>Datos del contribuyente</h2>
            <table>
                <tr><th>Razon social</th><td>{{ $empresa->razon_social }}</td></tr>
                <tr><th>NIT</th><td>{{ $empresa->nit }}</td></tr>
                <tr><th>Codigo de sistema</th><td>{{ $empresa->codigo_sistema ?: '—' }}</td></tr>
                <tr><th>Ambiente</th><td>{{ $empresa->codigo_ambiente === 1 ? 'Produccion' : 'Piloto' }}</td></tr>
                <tr><th>Modalidad</th><td>Electronica en linea</td></tr>
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

            {{-- Queda al final del lateral y no entre las acciones: es
                 irreversible y no debe caer cerca de un boton de uso diario. --}}
        <form method="POST" action="{{ route('admin.empresas.destroy', $empresa) }}"
              onsubmit="return confirm('Eliminar esta empresa y todos sus datos?')">
            @csrf
            @method('DELETE')
            <button class="btn rojo" type="submit">Eliminar empresa</button>
        </form>
        </aside>

        {{-- ================= PRINCIPAL: que hacer con el cliente ============ --}}
        <div class="principal">
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

        {{-- ---- Estructura fisica y codigos del SIN ---- --}}
        <div class="tarjeta">
            <h2>Sucursales y puntos de venta</h2>

            @forelse ($empresa->sucursales as $sucursal)
                @php
                    // Se listan los puntos de venta del SIN Y los locales, unidos por
                    // codigo. No alcanza con los del SIN: al dar de alta un cliente
                    // todavia no hay CUIS, sin CUIS no se puede consultar al SIN, y
                    // sin lista no habria donde apretar "Solicitar CUIS". Quedaba un
                    // callejon sin salida en el unico momento en que importa.
                    $siat = $puntosVentaSiat[$sucursal->id] ?? ['lista' => [], 'error' => null, 'consultado' => false];
                    $locales = $sucursal->puntosVenta->keyBy('codigo_punto_venta');
                    $remotos = collect($siat['lista'])->keyBy('codigo');

                    $codigosPv = $remotos->keys()
                        ->merge($locales->keys())
                        ->unique()
                        ->sort()
                        ->values();

                    // Locales que el SIN no conoce: no pueden emitir, pero hay que
                    // poder verlos para adoptarles un codigo.
                    // reject sobre existeEnElSiat y no sobre la marca de registro: el
                    // punto de venta 0 no esta "solo en local", el SIN lo conoce.
                    $soloLocales = $sucursal->puntosVenta->reject->existeEnElSiat();
                @endphp

                <div style="border-top:1px solid var(--borde); padding:12px 0;">
                    <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                        <div>
                            <strong>Sucursal {{ $sucursal->codigo_sucursal }}</strong> — {{ $sucursal->nombre }}
                            @if ($sucursal->codigo_sucursal === 0)
                                <span class="pill">casa matriz</span>
                            @endif
                        </div>
                        <span style="font-size:13px; color:var(--suave);">
                            {{ $codigosPv->count() }} punto(s) de venta
                        </span>
                    </div>

                    {{-- Municipio, direccion y telefono NO son decorativos: los tres
                         van en la cabecera de cada factura. Si faltan, el XML los
                         manda con xsi:nil y el SIN observa el documento. Por eso se
                         marcan en rojo en vez de dejarlos en blanco. --}}
                    <div class="codigos" style="margin-top:8px;">
                        <div class="fila">
                            <span class="etq">Municipio</span>
                            @if (filled($sucursal->municipio))
                                <span>{{ $sucursal->municipio }}</span>
                            @else
                                <span class="error">falta — viaja vacio en la factura</span>
                            @endif
                        </div>
                        <div class="fila">
                            <span class="etq">Direccion</span>
                            @if (filled($sucursal->direccion))
                                <span>{{ $sucursal->direccion }}</span>
                            @else
                                <span class="error">falta — viaja vacio en la factura</span>
                            @endif
                        </div>
                        <div class="fila">
                            <span class="etq">Telefono</span>
                            @if (filled($sucursal->telefono))
                                <span>{{ $sucursal->telefono }}</span>
                            @else
                                <span class="error">falta — viaja vacio en la factura</span>
                            @endif
                        </div>
                        <details style="margin-top:8px;">
                            <summary style="cursor:pointer; font-size:12px; color:var(--suave);">Corregir datos</summary>
                            <form method="POST" action="{{ route('admin.sucursales.update', $sucursal) }}"
                                  style="display:flex; gap:8px; align-items:end; flex-wrap:wrap; margin-top:8px;">
                                @csrf
                                @method('PUT')
                                <div class="campo" style="margin:0;"><label>Nombre</label>
                                    <input name="nombre" value="{{ $sucursal->nombre }}" required></div>
                                <div class="campo" style="margin:0;"><label>Municipio</label>
                                    <input name="municipio" value="{{ $sucursal->municipio }}" required></div>
                                <div class="campo" style="margin:0;"><label>Direccion</label>
                                    <input name="direccion" value="{{ $sucursal->direccion }}" required></div>
                                <div class="campo" style="margin:0;"><label>Telefono</label>
                                    <input name="telefono" value="{{ $sucursal->telefono }}" required></div>
                                <button class="btn gris" type="submit">Guardar</button>
                            </form>
                            {{-- El codigo no se edita: identifica la sucursal ante el SIN
                                 y ya esta escrito en las facturas emitidas y en sus CUF. --}}
                            <p style="font-size:12px; color:var(--suave); margin:8px 0 0;">
                                El codigo de sucursal no se puede cambiar.
                            </p>
                        </details>
                    </div>

                    @if ($siat['error'])
                        {{-- No se pudo preguntar al SIN. La ficha igual abre: se avisa
                             y se sigue mostrando lo que tenemos local. --}}
                        <p class="error" style="margin:12px 0 0;">
                            No se pudo leer la lista del SIAT: {{ $siat['error'] }}
                        </p>
                    @elseif ($siat['lista'] === [] && $codigosPv->isNotEmpty())
                        <p style="color:var(--suave); font-size:13px; margin:12px 0 0;">
                            El SIAT no tiene ningun punto de venta registrado en esta sucursal.
                        </p>
                    @endif

                    @if ($codigosPv->isEmpty())
                        <p style="color:var(--suave); font-size:13px; margin:12px 0 0;">
                            Sin puntos de venta. Crea uno abajo con el codigo 0 —el implicito
                            de la casa matriz— para poder pedir el primer CUIS.
                        </p>
                    @endif

                    @foreach ($codigosPv as $codigoPv)
                        @php
                            // Un codigo puede estar en el SIN, en local, o en los dos.
                            $remoto = $remotos[$codigoPv] ?? null;
                            $pv = $locales[$codigoPv] ?? null;
                            // El punto de venta 0 no lo devuelve el SIN: lo agrega el
                            // panel porque existe en toda sucursal.
                            $esImplicito = $remoto['implicito'] ?? false;
                            // Para el implicito manda el nombre local, que es el que
                            // escribio el operador; para el resto manda el del SIN.
                            $nombrePv = $esImplicito
                                ? ($pv->nombre ?? $remoto['nombre'])
                                : ($remoto['nombre'] ?? $pv->nombre);
                            $cuis = $pv?->cuisVigente();
                            $cufd = $pv?->cufdVigente();

                            // El CUFD dura 24 h: se avisa con 2 h de anticipacion,
                            // igual que el cron que los renueva.
                            $semCuis = RequisitosEtapa::vigencia($cuis?->fecha_vigencia, 24 * 15);
                            $semCufd = RequisitosEtapa::vigencia($cufd?->fecha_vigencia, 2);
                        @endphp

                        <div style="background:#f8fafc; border:1px solid var(--borde); border-radius:10px; padding:12px; margin:10px 0;">
                            <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                                <div>
                                    {{-- Si el SIN lo conoce, manda su nombre: es la fuente
                                         de verdad y lo local puede diferir. --}}
                                    <strong>PV {{ $codigoPv }}</strong> — {{ $nombrePv }}
                                    @if ($esImplicito)
                                        {{-- Existe siempre, sin registrarlo. Es con el que
                                             se puede facturar desde el minuto cero. --}}
                                        <x-badge color="verde" texto="implicito de la sucursal" />
                                        @unless ($pv)
                                            <x-badge color="gris" texto="sin configurar aca" />
                                        @endunless
                                    @elseif ($remoto && $pv)
                                        <x-badge color="verde" texto="en el SIAT y configurado" />
                                    @elseif ($remoto)
                                        {{-- Existe en el SIN pero no aca: no tiene CUIS,
                                             CUFD ni correlativo. --}}
                                        <x-badge color="gris" texto="sin configurar aca" />
                                    @elseif ($pv->existeEnElSiat())
                                        {{-- consultaPuntoVenta NO devuelve el punto de venta
                                             0: solo lista los registrados por API. Pero el 0
                                             existe igual —es el implicito de la sucursal— y
                                             tiene su propio CUIS y CUFD. Marcarlo "solo
                                             local" seria mentir: se puede facturar con el. --}}
                                        <x-badge color="verde" texto="implicito de la sucursal" />
                                    @else
                                        {{-- Existe aca pero el SIN no lo devolvio. Puede ser
                                             que falte registrarlo, o que todavia no se lo
                                             pudo consultar por no haber CUIS. --}}
                                        <x-badge color="ambar" texto="solo local" />
                                    @endif
                                    @if ($pv && ! $pv->activo)
                                        <x-badge color="rojo" texto="dado de baja" />
                                    @endif
                                </div>
                                @if ($pv)
                                    <span style="font-size:13px; color:var(--suave);">
                                        proxima factura: {{ $pv->siguiente_factura }}
                                    </span>
                                @endif
                            </div>

                            {{-- Datos propios del punto de venta. El tipo se resuelve
                                 contra el catalogo del SIN en vez de mostrar el numero
                                 pelado; si todavia no se sincronizo, se muestra el codigo. --}}
                            <div class="codigos">
                                <div class="fila">
                                    <span class="etq">Tipo</span>
                                    <span>
                                        {{-- La descripcion la da el SIN en la consulta; si no
                                             lo consultamos todavia, se resuelve del catalogo. --}}
                                        {{ $remoto['tipo']
                                            ?? $tiposPuntoVenta->firstWhere('codigo_clasificador', (string) $pv->tipo_punto_venta)?->descripcion
                                            ?? "codigo {$pv->tipo_punto_venta}" }}
                                    </span>
                                </div>
                                @if ($pv)
                                    <div class="fila">
                                        <span class="etq">Nombre local</span>
                                        <span>{{ $pv->nombre }}</span>
                                    </div>
                                    <div class="fila">
                                        <span class="etq">Facturas</span>
                                        <span>{{ $pv->facturas_count }} emitida(s)</span>
                                    </div>
                                @endif
                            </div>

                            @if ($pv)
                            {{-- Semaforos de los dos codigos. Sin CUFD vigente no se emite. --}}
                            <div style="display:flex; gap:18px; flex-wrap:wrap; margin:10px 0;">
                                <x-semaforo titulo="CUIS" :color="$semCuis['color']" :texto="$semCuis['texto']" />
                                <x-semaforo titulo="CUFD" :color="$semCufd['color']" :texto="$semCufd['texto']" />
                            </div>

                            {{-- Los codigos en si. El semaforo dice si sirven; esto dice
                                 cuales son, que es lo que hace falta para contrastar
                                 contra el SIN cuando algo no cuadra. --}}
                            <div class="codigos">
                                <div class="fila">
                                    <span class="etq">CUIS</span>
                                    @if ($cuis)
                                        <span class="val">{{ $cuis->codigo }}</span>
                                    @else
                                        <span class="vacio">sin CUIS vigente</span>
                                    @endif
                                </div>
                                <div class="fila">
                                    <span class="etq">CUFD</span>
                                    @if ($cufd)
                                        <span class="val">{{ $cufd->codigo }}</span>
                                    @else
                                        <span class="vacio">sin CUFD vigente</span>
                                    @endif
                                </div>
                                {{-- El codigo de control y la direccion del CUFD viven en
                                     el historial, no aca: son datos de diagnostico —el
                                     control se pega al final de cada CUF— y en la tarjeta
                                     solo hacian ruido. Lo que importa de un vistazo es si
                                     hay CUIS y CUFD vigentes. --}}
                            </div>

                            {{-- El historial vive en su propia pantalla: el SIN emite
                                 un CUFD por dia, asi que en un ano son cientos de filas
                                 y aca adentro no habia como paginarlas ni mirarlas. --}}
                            <p style="margin:10px 0;">
                                <a href="{{ route('admin.puntos-venta.codigos', $pv) }}"
                                   style="font-size:13px;">
                                    Ver historial de CUIS y CUFD
                                    ({{ $pv->cuis->count() }} CUIS, {{ $pv->cufds->count() }} CUFD) →
                                </a>
                            </p>

                            {{-- Solicitud al SIAT. El CUFD exige CUIS: el boton queda
                                 deshabilitado hasta tenerlo. --}}
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
                            </div>

                            @else
                                {{-- Existe en el SIN y no aca: el codigo esta libre para
                                     que lo tome un punto de venta local. Sin esto, el
                                     codigo se ve pero no se puede hacer nada con el. --}}
                                <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:10px; align-items:center;">
                                    {{-- Un solo clic: crea el registro local con el codigo, el
                                         nombre y el tipo que informo el SIN, lo marca como
                                         registrado —porque ya lo esta— y le pide CUIS y CUFD.
                                         Antes habia que crear uno con un codigo inventado y
                                         despues adoptarle este: dos pasos y un registro basura
                                         en el medio. --}}
                                    <form method="POST" action="{{ route('admin.sucursales.puntos-venta.configurar', $sucursal) }}" style="margin:0;">
                                        @csrf
                                        <input type="hidden" name="codigo_punto_venta" value="{{ $codigoPv }}">
                                        <input type="hidden" name="nombre" value="{{ $remoto['nombre'] }}">
                                        {{-- El SIN informa el tipo por su descripcion; el numero
                                             sale del catalogo sincronizado. Si no esta, el
                                             controlador cae en 1. --}}
                                        <input type="hidden" name="tipo_punto_venta"
                                               value="{{ $tiposPuntoVenta->firstWhere('descripcion', $remoto['tipo'])?->codigo_clasificador }}">
                                        <button class="btn" type="submit" style="font-size:12px;">
                                            Configurar aca
                                        </button>
                                    </form>

                                    {{-- El camino viejo queda para reconciliar: si un punto de
                                         venta local quedo con el codigo equivocado, se le pasa
                                         este sin crear otro. --}}
                                    @foreach ($soloLocales as $local)
                                        <form method="POST" action="{{ route('admin.puntos-venta.adoptar-codigo', $local) }}" style="margin:0;">
                                            @csrf
                                            <input type="hidden" name="codigo_punto_venta" value="{{ $codigoPv }}">
                                            <button class="btn gris" type="submit" style="font-size:12px;">
                                                o pasarselo a «{{ $local->nombre }}»
                                            </button>
                                        </form>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach

                @if ($soloLocales->isNotEmpty())
                    {{-- No se listan arriba porque no pueden emitir, pero tampoco se
                         ocultan en silencio: uno recien creado pareceria haberse
                         perdido. Se adoptan un codigo desde la consulta al SIAT. --}}
                    <p style="color:var(--suave); font-size:12px; margin:10px 0 0;">
                        {{ $soloLocales->count() }} punto(s) de venta creado(s) solo en local
                        ({{ $soloLocales->pluck('nombre')->implode(', ') }}): no se listan porque
                        el SIN no los conoce y no pueden emitir.
                    </p>
                @endif

                {{-- ---- Acciones DE LA SUCURSAL, no de un punto de venta ----
                         Iban sueltas justo debajo de la ultima tarjeta y parecian
                         parte de ella: el "Codigo PV: 0" del formulario de alta se
                         leia como si existiera un punto de venta 0. --}}
                    <div style="border-top:1px dashed var(--borde); margin-top:14px; padding-top:12px;">
                        <strong style="font-size:13px; color:var(--suave);">
                            Acciones de la sucursal {{ $sucursal->codigo_sucursal }}
                        </strong>
                    </div>


                    {{-- Unica accion de la sucursal. No hay alta manual de puntos de
                         venta: crear uno local con un codigo inventado produce algo que
                         no puede facturar —el codigo entra al CUF y el SIN no lo
                         reconoce—. Los que existen se traen de aca y se configuran con
                         el boton de cada uno. El punto de venta 0 se crea solo junto
                         con la sucursal. --}}
                    <form method="POST" action="{{ route('admin.sucursales.puntos-venta.consultar', $sucursal) }}" style="margin:0 0 10px;">
                        @csrf
                        <button class="btn gris" type="submit">Consultar los del SIAT</button>
                    </form>

                    {{-- Registrar uno NUEVO en el SIN. Va colapsado y con confirmacion
                         porque es irreversible: el SIN crea uno nuevo en cada llamada,
                         le asigna EL el codigo, y despues no se puede borrar —solo
                         cerrar, y un cerrado no se reabre—. Casi siempre lo que se busca
                         ya existe arriba y alcanza con «Configurar aca». --}}
                    <details>
                        <summary style="cursor:pointer; font-size:13px; color:#b45309;">
                            Registrar un punto de venta NUEVO en el SIAT
                        </summary>

                        <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:12px; margin-top:8px;">
                            <p style="font-size:13px; color:#92400e; margin:0 0 10px;">
                                <strong>Esto es irreversible.</strong> El SIN crea un punto de venta
                                nuevo y le asigna el codigo —no se elige—. No se puede borrar:
                                solo cerrar, y uno cerrado no se reabre. Si el que necesitas ya
                                aparece en la lista de arriba, usa «Configurar aca» en vez de esto.
                            </p>

                            <form method="POST" action="{{ route('admin.sucursales.puntos-venta.registrar', $sucursal) }}"
                                  style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;"
                                  onsubmit="return confirm('Se va a crear un punto de venta NUEVO en Impuestos Nacionales. No se puede deshacer. Continuar?')">
                                @csrf
                                <div class="campo" style="margin:0;">
                                    <label>Nombre</label>
                                    <input name="nombre" required placeholder="Ej: CAJA 2">
                                </div>
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
                                <button class="btn rojo" type="submit">Registrar en el SIAT</button>
                            </form>
                        </div>
                    </details>
                </div>
            @empty
                <p style="color:var(--suave);">
                    Sin sucursales. El primer paso es registrar la casa matriz (codigo 0).
                </p>
            @endforelse

            <h2 style="margin-top:18px;">Nueva sucursal</h2>
            <form method="POST" action="{{ route('admin.empresas.sucursales.store', $empresa) }}" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;">
                @csrf
                {{-- Sugiere el siguiente libre: 0 es la casa matriz y solo puede
                     haber una, asi que un 0 fijo chocaba con el unique. --}}
                <div class="campo" style="margin:0;"><label>Codigo</label>
                    <input name="codigo_sucursal" type="number"
                           value="{{ $empresa->sucursales->isEmpty() ? 0 : (int) $empresa->sucursales->max('codigo_sucursal') + 1 }}" required>
                </div>
                <div class="campo" style="margin:0;"><label>Nombre</label>
                    <input name="nombre" value="{{ $empresa->sucursales->isEmpty() ? 'Casa Matriz' : '' }}" required>
                </div>
                {{-- Municipio, direccion y telefono van en la cabecera de CADA
                     factura. El formulario no los pedia y no habia edicion: toda
                     sucursal nacia incompleta y el SIN observaba sus facturas. --}}
                <div class="campo" style="margin:0;"><label>Municipio</label><input name="municipio" required></div>
                <div class="campo" style="margin:0;"><label>Direccion</label><input name="direccion" required></div>
                <div class="campo" style="margin:0;"><label>Telefono</label><input name="telefono" required></div>
                <button class="btn" type="submit">+ Sucursal</button>
            </form>
            <p style="font-size:12px; color:var(--suave); margin:8px 0 0;">
                El codigo tiene que ser el MISMO que le asigno el SIN en la Oficina
                Virtual (la casa matriz es la 0). Este sistema no crea sucursales en
                el SIAT: si el codigo no existe alla, el SIN rechaza sus facturas.
            </p>
        </div>

        {{-- ---- Piloto ---- --}}
        <div class="tarjeta">
            <h2>Piloto del SIN</h2>
            <div style="display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
                <div style="flex:1; min-width:220px;">
                    <div class="progreso"><span style="width: {{ $progresoPiloto['porcentaje'] }}%;"></span></div>
                    <div style="font-size:13px; color:var(--suave); margin-top:6px;">
                        {{ $progresoPiloto['exitosos'] }}/{{ $progresoPiloto['total'] }} pruebas correctas en las etapas del portal
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
        </div>
    </div>
@endsection
