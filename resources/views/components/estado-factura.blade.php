{{-- Badge del estado de una factura. Ojo: CONTINGENCIA es ambar, no rojo:
     esa factura ya es valida, solo falta transmitirla. --}}
@props(['estado'])

@php($visual = App\Services\Panel\EstadosVisuales::factura($estado))

<x-badge :color="$visual['color']" :texto="$visual['etiqueta']" {{ $attributes }} />
