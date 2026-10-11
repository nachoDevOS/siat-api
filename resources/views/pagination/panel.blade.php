{{-- Paginacion del panel. La vista por defecto de Laravel trae clases de
     Tailwind y el panel no lo carga: salia sin estilo y en ingles. --}}
@if ($paginator->hasPages())
    <nav class="paginacion" role="navigation" aria-label="Paginacion">
        <span class="resumen">
            Mostrando {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }}
        </span>

        <span class="paginas">
            @if ($paginator->onFirstPage())
                <span class="pag deshab" aria-disabled="true">&lsaquo; Anterior</span>
            @else
                <a class="pag" href="{{ $paginator->previousPageUrl() }}" rel="prev">&lsaquo; Anterior</a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="pag deshab" aria-disabled="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="pag actual" aria-current="page">{{ $page }}</span>
                        @else
                            <a class="pag" href="{{ $url }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a class="pag" href="{{ $paginator->nextPageUrl() }}" rel="next">Siguiente &rsaquo;</a>
            @else
                <span class="pag deshab" aria-disabled="true">Siguiente &rsaquo;</span>
            @endif
        </span>
    </nav>
@endif
