@props([
    'href' => null,
    'action' => null,
    'method' => 'POST',
    'icon' => null,
    'tone' => 'default',
])
@php
    $classes = ['dropdown__item', 'dropdown__item--danger' => $tone === 'danger'];
    $method = strtoupper((string) $method);
    $safeHref = filled($href) && ! preg_match('/^\s*(?:javascript|data|vbscript):/i', (string) $href) ? (string) $href : (filled($href) ? '#' : null);
    $rel = $attributes->get('target') === '_blank' ? 'noopener noreferrer' : null;
@endphp
@if (filled($action))
    {{-- A real form, so CSRF and method spoofing work. Destructive actions belong in a confirm dialog opened from a plain item (data-dialog-open). --}}
    <form method="POST" action="{{ $action }}" class="dropdown__form" data-submit-once>
        @csrf
        @if (! in_array($method, ['GET', 'POST'], true))@method($method)@endif
        <button type="submit" {{ $attributes->class($classes) }}>
            @if (filled($icon))<x-ui.icon :name="$icon" :size="16" />@endif
            <span>{{ $slot }}</span>
        </button>
    </form>
@elseif ($safeHref !== null)
    <a href="{{ $safeHref }}" {{ $attributes->class($classes)->merge(['rel' => $rel]) }}>
        @if (filled($icon))<x-ui.icon :name="$icon" :size="16" />@endif
        <span>{{ $slot }}</span>
    </a>
@else
    <button type="button" {{ $attributes->class($classes) }}>
        @if (filled($icon))<x-ui.icon :name="$icon" :size="16" />@endif
        <span>{{ $slot }}</span>
    </button>
@endif
