@props([
    'label' => null,
    'value' => null,
    'hint' => null,
    'icon' => null,
    'href' => null,
])
@php
    $safeHref = filled($href) && ! preg_match('/^\s*(?:javascript|data|vbscript):/i', (string) $href) ? (string) $href : (filled($href) ? '#' : null);
    $tag = $safeHref !== null ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($safeHref !== null) href="{{ $safeHref }}" @endif {{ $attributes->class(['stat', 'stat--link' => $safeHref !== null]) }}>
    <div class="stat__head">
        <span class="stat__label">{{ $label }}</span>
        @if (filled($icon))<span class="stat__icon"><x-ui.icon :name="$icon" :size="18" /></span>@endif
    </div>
    <span class="stat__value">{{ $value }}</span>
    @if (filled($hint))<span class="stat__hint">{{ $hint }}</span>@endif
</{{ $tag }}>
