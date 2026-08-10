{{-- Ciclo de vida del cliente ante el SIN, de un vistazo.
     OBSERVADO no es un paso mas: se pinta como alerta sobre el paso de pruebas,
     que es de donde el SIN observa al contribuyente. --}}
@props(['estado', 'compacto' => false])

@php
    use App\Models\Empresa;
    use App\Services\Panel\EstadosVisuales;

    $ciclo = EstadosVisuales::CICLO_EMPRESA;
    $actual = EstadosVisuales::posicionEnCiclo($estado);
    $observado = $estado === Empresa::ESTADO_OBSERVADO;
@endphp

<div {{ $attributes->merge(['class' => 'stepper'.($compacto ? ' stepper-compacto' : '')]) }}>
    @foreach ($ciclo as $indice => $etapa)
        @php
            $visual = EstadosVisuales::empresa($etapa);
            $estadoPaso = match (true) {
                $observado && $indice === $actual => 'alerta',
                $indice < $actual => 'hecho',
                $indice === $actual => 'actual',
                default => 'pendiente',
            };
        @endphp

        <div class="paso paso-{{ $estadoPaso }}" title="{{ $visual['etiqueta'] }}">
            <span class="marca">{{ $estadoPaso === 'hecho' ? '✓' : ($estadoPaso === 'alerta' ? '!' : $indice + 1) }}</span>
            @unless ($compacto)
                <span class="rotulo">{{ $visual['etiqueta'] }}</span>
            @endunless
        </div>

        @if (! $loop->last)
            <span class="linea linea-{{ $indice < $actual ? 'hecha' : 'pendiente' }}"></span>
        @endif
    @endforeach
</div>
