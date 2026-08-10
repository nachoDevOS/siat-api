{{-- Etiqueta de estado. El color sale siempre de EstadosVisuales, nunca a mano. --}}
@props(['color' => 'gris', 'texto'])

<span {{ $attributes->merge(['class' => 'badge badge-'.$color]) }}>{{ $texto }}</span>
