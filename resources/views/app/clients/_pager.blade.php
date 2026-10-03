{{-- "Showing 1–8 of 48 clients" + a window of five page links (comp 10). Needs: $clients (LengthAwarePaginator). --}}
@php
    $last = $clients->lastPage();
    $current = $clients->currentPage();
    $from = max(1, min($current - 2, $last - 4));
    $to = min($last, $from + 4);
@endphp
<nav class="clients-pager" aria-label="Pagination">
    <p class="clients-pager__summary">
        @if ($clients->total() > 0)
            Showing {{ number_format($clients->firstItem()) }}–{{ number_format($clients->lastItem()) }} of {{ number_format($clients->total()) }} {{ \Illuminate\Support\Str::plural('client', $clients->total()) }}
        @else
            Showing 0 clients
        @endif
    </p>
    @if ($last > 1)
        <ul class="clients-pager__list">
            <li>
                @if ($clients->onFirstPage())
                    <span class="pager-btn is-disabled" aria-disabled="true"><x-ui.icon name="chevron-left" :size="14" /><span class="sr-only">Previous page</span></span>
                @else
                    <a href="{{ $clients->previousPageUrl() }}" rel="prev" class="pager-btn"><x-ui.icon name="chevron-left" :size="14" /><span class="sr-only">Previous page</span></a>
                @endif
            </li>
            @foreach (range($from, $to) as $page)
                <li>
                    @if ($page === $current)
                        <span class="pager-page is-current" aria-current="page"><span class="sr-only">Page </span>{{ $page }}</span>
                    @else
                        <a href="{{ $clients->url($page) }}" class="pager-page"><span class="sr-only">Go to page </span>{{ $page }}</a>
                    @endif
                </li>
            @endforeach
            <li>
                @if ($clients->hasMorePages())
                    <a href="{{ $clients->nextPageUrl() }}" rel="next" class="pager-btn pager-btn--next"><span class="sr-only">Next page</span><x-ui.icon name="chevron-right" :size="14" /></a>
                @else
                    <span class="pager-btn pager-btn--next is-disabled" aria-disabled="true"><span class="sr-only">Next page</span><x-ui.icon name="chevron-right" :size="14" /></span>
                @endif
            </li>
        </ul>
    @endif
</nav>
