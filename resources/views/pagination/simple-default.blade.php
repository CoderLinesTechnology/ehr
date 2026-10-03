@if ($paginator->hasPages())
<nav class="pagination" role="navigation" aria-label="Pagination">
    <p class="pagination__summary">Page <strong>{{ $paginator->currentPage() }}</strong></p>

    <ul class="pagination__list">
        <li>
            @if ($paginator->onFirstPage())
                <span class="pagination__link is-disabled" aria-disabled="true"><x-ui.icon name="chevron-left" :size="16" /><span>Previous</span></span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="pagination__link"><x-ui.icon name="chevron-left" :size="16" /><span>Previous</span></a>
            @endif
        </li>
        <li>
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="pagination__link"><span>Next</span><x-ui.icon name="chevron-right" :size="16" /></a>
            @else
                <span class="pagination__link is-disabled" aria-disabled="true"><span>Next</span><x-ui.icon name="chevron-right" :size="16" /></span>
            @endif
        </li>
    </ul>
</nav>
@endif
