@props([
    'tabs' => [],
    'label' => 'Sections',
    'variant' => 'underline',
])
@php
    $safe = static fn ($url) => filled($url) && ! preg_match('/^\s*(?:javascript|data|vbscript):/i', (string) $url) ? (string) $url : '#';
@endphp
{{-- Server-side tabs: every tab is a real link to its own URL, the current one carries aria-current. --}}
<nav {{ $attributes->class(['tabs', 'tabs--pills' => $variant === 'pills']) }} aria-label="{{ $label }}" data-tabs>
    <ul class="tabs__list">
        @foreach ($tabs as $tab)
            <li class="tabs__item">
                <a href="{{ $safe($tab['url'] ?? null) }}" class="tabs__link" @if (! empty($tab['active'])) aria-current="page" @endif>
                    @if (! empty($tab['icon']))<x-ui.icon :name="$tab['icon']" :size="16" />@endif
                    <span>{{ $tab['label'] ?? '' }}</span>
                    @if (isset($tab['count']) && $tab['count'] !== null)<span class="tabs__count">{{ $tab['count'] }}</span>@endif
                </a>
            </li>
        @endforeach
    </ul>
</nav>
