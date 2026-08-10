{{-- Punto de color + texto. Se usa para toda vigencia (certificado, CUIS,
     CUFD, CAFC) para que el mismo color signifique lo mismo en todo el panel. --}}
@props(['color' => 'gris', 'texto', 'titulo' => null])

<span {{ $attributes->merge(['class' => 'semaforo']) }}>
    <span class="punto punto-{{ $color }}"></span>
    @if ($titulo)
        <strong>{{ $titulo }}</strong>
    @endif
    <span class="txt">{{ $texto }}</span>
</span>
