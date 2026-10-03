@props([
    'items' => [],
    'label' => 'View',
])
@php
    // Each item: ['label' => '', 'url' => '…', 'active' => bool, 'icon' => null]. With a url it is a link (aria-current);
    // without one it is a button (aria-pressed) that page JavaScript can wire up.
    $safe = static fn ($url) => filled($url) && ! preg_match('/^\s*(?:javascript|data|vbscript):/i', (string) $url) ? (string) $url : null;
@endphp
<div {{ $attributes->class(['segmented']) }} role="group" aria-label="{{ $label }}">
    @foreach ($items as $item)
        @php($url = $safe($item['url'] ?? null))
        @if ($url !== null)
            <a href="{{ $url }}" class="segmented__item" @if (! empty($item['active'])) aria-current="true" @endif>
                @if (! empty($item['icon']))<x-ui.icon :name="$item['icon']" :size="16" />@endif<span>{{ $item['label'] ?? '' }}</span>
            </a>
        @else
            <button type="button" class="segmented__item" aria-pressed="{{ ! empty($item['active']) ? 'true' : 'false' }}" @if (! empty($item['key'])) data-segment="{{ $item['key'] }}" @endif>
                @if (! empty($item['icon']))<x-ui.icon :name="$item['icon']" :size="16" />@endif<span>{{ $item['label'] ?? '' }}</span>
            </button>
        @endif
    @endforeach
</div>
