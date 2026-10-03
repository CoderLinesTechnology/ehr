@props([
    'done' => false,
    'title' => null,
    'description' => null,
    'href' => null,
])
@php
    $safeHref = filled($href) && ! preg_match('/^\s*(?:javascript|data|vbscript):/i', (string) $href) ? (string) $href : (filled($href) ? '#' : null);
@endphp
{{-- Render inside <ul class="checklist" role="list">. Completion is shown by icon AND text, never by colour alone. --}}
<li {{ $attributes->class(['checklist-item', 'is-done' => $done]) }}>
    <span class="checklist-item__status"><x-ui.icon :name="$done ? 'check-circle' : 'circle'" :size="22" /></span>
    <div class="checklist-item__text">
        <p class="checklist-item__title">
            @if ($safeHref !== null)<a href="{{ $safeHref }}" class="checklist-item__link">{{ $title }}</a>@else{{ $title }}@endif
            <span class="sr-only">{{ $done ? '(completed)' : '(not completed yet)' }}</span>
        </p>
        @if (filled($description))<p class="checklist-item__description">{{ $description }}</p>@endif
    </div>
    @if ($safeHref !== null)<x-ui.icon name="chevron-right" :size="18" class="checklist-item__go" />@endif
</li>
