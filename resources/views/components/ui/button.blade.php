@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'href' => null,
    'icon' => null,
    'iconRight' => null,
    'block' => false,
])
@php
    $variant = in_array($variant, ['primary', 'secondary', 'tonal', 'neutral', 'success', 'warning', 'danger', 'info', 'ghost', 'link'], true) ? $variant : 'primary';
    $size = in_array($size, ['xs', 'sm', 'md', 'lg'], true) ? $size : 'md';
    // Heights: xs 24 / sm 32 (compact, filter panels) / md 40 (default) / lg 44. Sizes 28 and 36 on the sheet are not used by any screen.
    $iconSize = ['xs' => 14, 'sm' => 16, 'md' => 18, 'lg' => 18][$size];
    $hasLabel = ! $slot->isEmpty();
    $classes = [
        'btn',
        'btn--'.$variant,
        'btn--'.$size => $size !== 'md',
        'btn--icon' => $icon && ! $hasLabel && ! $iconRight,
        'btn--block' => $block,
    ];
    $disabled = $attributes->has('disabled') && $attributes->get('disabled') !== false;
    // A caller-supplied URL must never become a script URL.
    $safeHref = filled($href) && ! preg_match('/^\s*(?:javascript|data|vbscript):/i', (string) $href) ? (string) $href : (filled($href) ? '#' : null);
    $rel = $attributes->get('target') === '_blank' ? 'noopener noreferrer' : null;
@endphp
@if ($safeHref !== null && $disabled)
<span {{ $attributes->except('disabled')->class($classes)->class('is-disabled') }} role="link" aria-disabled="true">
    @if ($icon)<x-ui.icon :name="$icon" :size="$iconSize" />@endif
    @if ($hasLabel)<span class="btn__label">{{ $slot }}</span>@endif
    @if ($iconRight)<x-ui.icon :name="$iconRight" :size="$iconSize" />@endif
</span>
@elseif ($safeHref !== null)
<a href="{{ $safeHref }}" {{ $attributes->class($classes)->merge(['rel' => $rel]) }}>
    @if ($icon)<x-ui.icon :name="$icon" :size="$iconSize" />@endif
    @if ($hasLabel)<span class="btn__label">{{ $slot }}</span>@endif
    @if ($iconRight)<x-ui.icon :name="$iconRight" :size="$iconSize" />@endif
</a>
@else
<button type="{{ in_array($type, ['button', 'submit', 'reset'], true) ? $type : 'button' }}" {{ $attributes->class($classes) }}>
    @if ($icon)<x-ui.icon :name="$icon" :size="$iconSize" />@endif
    @if ($hasLabel)<span class="btn__label">{{ $slot }}</span>@endif
    @if ($iconRight)<x-ui.icon :name="$iconRight" :size="$iconSize" />@endif
</button>
@endif
