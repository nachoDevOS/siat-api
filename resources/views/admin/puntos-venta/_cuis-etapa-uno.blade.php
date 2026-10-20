{{-- Pruebas de CUIS de la etapa I de un punto de venta: una pastilla por
     version con el CUIS que conto, o el motivo y "Reintentar" si no conto.
     Recibe $empresa y $pruebas (CuisEtapaUno). Lo usan la ficha y la
     pantalla de codigos, para que digan lo mismo. --}}
<div class="etapa-uno">
    @foreach ($pruebas as $prueba)
        <div class="etapa-uno-prueba {{ $prueba['contada'] ? 'ok' : ($prueba['intentada'] ? 'fallo' : 'pendiente') }}">
            <span class="etapa-uno-version">{{ $prueba['version'] }}</span>
            @if ($prueba['contada'])
                <span class="etapa-uno-marca">✓</span>
                <code>{{ $prueba['cuis'] }}</code>
                <span class="etapa-uno-nota">contada</span>
            @else
                <span class="etapa-uno-marca">{{ $prueba['intentada'] ? '✗' : '·' }}</span>
                <span class="etapa-uno-nota" title="{{ $prueba['error'] }}">
                    {{ $prueba['intentada'] ? \Illuminate\Support\Str::limit($prueba['error'] ?? 'sin detalle', 70) : 'sin pedir' }}
                </span>
                <form method="POST" action="{{ route('admin.pruebas.cuis-nuevo', [$empresa, $prueba['caso']]) }}" style="margin:0;"
                      data-titulo="Pedir CUIS nuevo por {{ $prueba['version'] }}" data-boton="Pedir"
                      data-confirmar="Si el SIN ya tiene un CUIS vigente en este punto de venta, se cierran las operaciones del sistema y se pide otro. El CUIS es uno solo para V2 y V3: el actual deja de servir.">
                    @csrf
                    <button class="btn" type="submit" style="padding:4px 10px; font-size:12px;">
                        {{ $prueba['intentada'] ? 'Reintentar' : 'Pedir' }}
                    </button>
                </form>
            @endif
        </div>
    @endforeach
</div>

@once
    <style>
        .etapa-uno { display: flex; flex-wrap: wrap; gap: 8px; }
        .etapa-uno-prueba { display: flex; align-items: center; gap: 8px; padding: 6px 10px; border-radius: 10px;
            border: 1px solid var(--borde); background: var(--panel); font-size: 13px; min-width: 0; flex: 1 1 260px; }
        .etapa-uno-prueba.ok { border-color: #a7f3d0; background: #ecfdf5; }
        .etapa-uno-prueba.fallo { border-color: #fecaca; background: #fef2f2; }
        .etapa-uno-version { font-weight: 700; font-size: 12px; padding: 2px 7px; border-radius: 6px; background: #0f172a; color: #fff; }
        .etapa-uno-marca { font-weight: 700; }
        .etapa-uno-prueba.ok .etapa-uno-marca { color: var(--ok); }
        .etapa-uno-prueba.fallo .etapa-uno-marca { color: var(--no); }
        .etapa-uno-nota { color: var(--suave); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; flex: 1; }
        .etapa-uno-prueba form { margin-left: auto; }
    </style>
@endonce
