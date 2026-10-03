@props([
    'action' => null,
    'resetUrl' => null,
    'title' => 'Filters',
    'applyLabel' => 'Apply Filters',
])
@php
    $safe = static fn ($url) => filled($url) && ! preg_match('/^\s*(?:javascript|data|vbscript):/i', (string) $url) ? (string) $url : null;
@endphp
{{-- Right-rail filter card (comp 10): title + Reset, labelled compact fields in the slot (use <x-ui.field> + <x-ui.select class="input--sm">), primary Apply button. --}}
<form method="GET" action="{{ $action ?? url()->current() }}" {{ $attributes->class(['card', 'filter-panel']) }} aria-label="{{ $title }}">
    <div class="filter-panel__head">
        <h2 class="filter-panel__title">{{ $title }}</h2>
        @if ($safe($resetUrl))<a href="{{ $safe($resetUrl) }}" class="filter-panel__reset">Reset</a>@endif
    </div>
    <div class="filter-panel__fields">{{ $slot }}</div>
    <x-ui.button type="submit" icon="search" :block="true" class="filter-panel__apply" size="sm">{{ $applyLabel }}</x-ui.button>
</form>
