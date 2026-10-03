@props([
    'column',
    'label',
    'th' => false,
    'param' => 'sort',
    'directionParam' => 'direction',
])
@php
    $query = request();
    $current = $query->query($param);
    $direction = strtolower((string) (is_string($query->query($directionParam)) ? $query->query($directionParam) : '')) === 'desc' ? 'desc' : 'asc';
    $active = is_string($current) && $current === $column;
    $next = $active && $direction === 'asc' ? 'desc' : 'asc';
    // Keeps every other query parameter (filters, search); drops the page so a new sort starts at page 1.
    $url = $query->fullUrlWithQuery([$param => $column, $directionParam => $next, 'page' => null]);
    $state = $active ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none';
    $icon = $active ? ($direction === 'asc' ? 'arrow-up' : 'arrow-down') : 'chevrons-up-down';
@endphp
@if ($th)<th scope="col" {{ $attributes->class(['sortable']) }} @if ($active) aria-sort="{{ $state }}" @endif>@endif
<a href="{{ $url }}" class="sort-link @if ($active) is-active @endif" data-sort-state="{{ $state }}">{{ $label }}<x-ui.icon :name="$icon" :size="14" class="sort-link__icon" />@if ($active)<span class="sr-only">, sorted {{ $state }}</span>@endif</a>
@if ($th)</th>@endif
