@props([
    'label' => null,
    'value' => null,
    'hint' => null,
    'icon' => null,
    'href' => null,
    'tone' => 'blue',
    'shape' => 'square',
    'trend' => null,
    'trendLabel' => 'vs. last 30 days',
    'alert' => null,
])
@php
    // trend: a signed number of percent (12 -> "up 12%", -8 -> "down 8%"), null to hide.
    // alert: short red text with an alert icon instead of a trend ("1 new", "2 overdue").
    $safeHref = filled($href) && ! preg_match('/^\s*(?:javascript|data|vbscript):/i', (string) $href) ? (string) $href : (filled($href) ? '#' : null);
    $tag = $safeHref !== null ? 'a' : 'div';
    $hasTrend = is_numeric($trend);
    $down = $hasTrend && (float) $trend < 0;
    $caption = filled($hint) ? $hint : ($hasTrend ? $trendLabel : null);
@endphp
<{{ $tag }} @if ($safeHref !== null) href="{{ $safeHref }}" @endif {{ $attributes->class(['stat', 'stat--link' => $safeHref !== null]) }}>
    @if (filled($icon))<x-ui.icon-tile :icon="$icon" :tone="$tone" :shape="$shape" :size="$shape === 'circle' ? 42 : 36" :icon-size="20" />@endif
    <span class="stat__label">{{ $label }}</span>
    <span class="stat__value">{{ $value }}</span>
    @if ($hasTrend)
        <span class="stat__delta {{ $down ? 'stat__delta--down' : '' }}"><x-ui.icon :name="$down ? 'arrow-down' : 'arrow-up'" :size="12" /><span>{{ number_format(abs((float) $trend), 0) }}%</span><span class="sr-only">{{ $down ? 'decrease' : 'increase' }}</span></span>
    @elseif (filled($alert))
        <span class="stat__delta stat__delta--alert"><x-ui.icon name="circle-alert" :size="16" /><span>{{ $alert }}</span></span>
    @endif
    @if (filled($caption))<span class="stat__hint">{{ $caption }}</span>@endif
</{{ $tag }}>
