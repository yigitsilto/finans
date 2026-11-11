@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Sayfalama">
        @if ($paginator->onFirstPage())
            <span class="disabled">‹ Önceki</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ Önceki</a>
        @endif

        <span class="muted">Sayfa {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }} · {{ $paginator->total() }} kayıt</span>

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next">Sonraki ›</a>
        @else
            <span class="disabled">Sonraki ›</span>
        @endif
    </nav>
@endif
