@props([
    'name' => 'q',
    'value' => null,
    'placeholder' => 'Search',
    'action' => null,
    'keep' => [],
    'label' => null,
    'id' => null,
])
@php
    $action = filled($action) ? (string) $action : url()->current();
    $sid = $id ?: 'search-'.\Illuminate\Support\Str::slug($name);
    $current = $value ?? request()->query($name);
    $current = is_scalar($current) ? (string) $current : '';

    // Other query parameters (filters, sort) ride along as hidden inputs; the page number is dropped on purpose.
    $kept = [];
    foreach ((array) $keep as $key) {
        $v = request()->query($key);
        if (is_array($v)) {
            foreach ($v as $item) {
                if (is_scalar($item) && (string) $item !== '') {
                    $kept[] = [$key.'[]', (string) $item];
                }
            }
        } elseif (is_scalar($v) && (string) $v !== '') {
            $kept[] = [$key, (string) $v];
        }
    }
    $clearUrl = $action.($kept !== [] ? (str_contains($action, '?') ? '&' : '?').implode('&', array_map(static fn ($p) => rawurlencode($p[0]).'='.rawurlencode($p[1]), $kept)) : '');
@endphp
<form method="GET" action="{{ $action }}" role="search" {{ $attributes->class(['search-input']) }}>
    @foreach ($kept as [$k, $v])<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
    <label for="{{ $sid }}" class="sr-only">{{ $label ?? $placeholder }}</label>
    <button type="submit" class="search-input__submit" aria-label="Search"><x-ui.icon name="search" :size="18" /></button>
    <input type="search" id="{{ $sid }}" name="{{ $name }}" value="{{ $current }}" placeholder="{{ $placeholder }}" class="input search-input__control" autocomplete="off" enterkeyhint="search">
    @if ($current !== '')<a href="{{ $clearUrl }}" class="search-input__clear" aria-label="Clear search"><x-ui.icon name="x" :size="16" /></a>@endif
</form>
