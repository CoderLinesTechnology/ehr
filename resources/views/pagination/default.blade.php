@if ($paginator->hasPages())
<nav class="pagination" role="navigation" aria-label="Pagination">
    <p class="pagination__summary">
        Showing <strong>{{ number_format($paginator->firstItem()) }}</strong> to <strong>{{ number_format($paginator->lastItem()) }}</strong> of <strong>{{ number_format($paginator->total()) }}</strong> results
    </p>

    <ul class="pagination__list">
        <li>
            @if ($paginator->onFirstPage())
                <span class="pagination__link is-disabled" aria-disabled="true"><x-ui.icon name="chevron-left" :size="16" /><span class="sr-only">Previous page</span></span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="pagination__link"><x-ui.icon name="chevron-left" :size="16" /><span class="sr-only">Previous page</span></a>
            @endif
        </li>

        @foreach ($elements as $element)
            @if (is_string($element))
                <li><span class="pagination__gap" aria-hidden="true">{{ $element }}</span></li>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    <li>
                        @if ($page == $paginator->currentPage())
                            <span class="pagination__link is-current" aria-current="page"><span class="sr-only">Page </span>{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="pagination__link"><span class="sr-only">Go to page </span>{{ $page }}</a>
                        @endif
                    </li>
                @endforeach
            @endif
        @endforeach

        <li>
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="pagination__link"><span class="sr-only">Next page</span><x-ui.icon name="chevron-right" :size="16" /></a>
            @else
                <span class="pagination__link is-disabled" aria-disabled="true"><span class="sr-only">Next page</span><x-ui.icon name="chevron-right" :size="16" /></span>
            @endif
        </li>
    </ul>
</nav>
@endif
