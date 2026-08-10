{{-- Badge del estado de un cliente en su ciclo de vida ante el SIN. --}}
@props(['estado'])

@php($visual = App\Services\Panel\EstadosVisuales::empresa($estado))

<x-badge :color="$visual['color']" :texto="$visual['etiqueta']" {{ $attributes }} />
