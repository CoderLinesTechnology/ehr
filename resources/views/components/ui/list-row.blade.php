@props([
    'title' => null,
    'subtitle' => null,
    'href' => null,
    'chevron' => false,
])
@php
    $safeHref = filled($href) && ! preg_match('/^\s*(?:javascript|data|vbscript):/i', (string) $href) ? (string) $href : (filled($href) ? '#' : null);
    $tag = $safeHref !== null ? 'a' : 'div';
@endphp
{{-- A row inside a card: optional lead slot (avatar, icon tile, time), title + subtitle, optional trail slot (badge, time, button). --}}
<{{ $tag }} @if ($safeHref !== null) href="{{ $safeHref }}" @endif {{ $attributes->class(['list-row']) }}>
    @if (isset($lead) && ! $lead->isEmpty())<span class="list-row__lead">{{ $lead }}</span>@endif
    <span class="list-row__text">
        <span class="list-row__title">{{ $title }}</span>
        @if (filled($subtitle))<span class="list-row__sub">{{ $subtitle }}</span>@endif
        {{ $slot }}
    </span>
    @if ((isset($trail) && ! $trail->isEmpty()) || ($chevron && $safeHref !== null))
        <span class="list-row__trail">
            @if (isset($trail)){{ $trail }}@endif
            @if ($chevron && $safeHref !== null)<x-ui.icon name="chevron-right" :size="16" class="list-row__go" />@endif
        </span>
    @endif
</{{ $tag }}>
