@props([
    'items' => [],
    'label' => 'Breadcrumb',
])
@php
    $items = array_values($items);
    $last = count($items) - 1;
    $safe = static fn ($url) => filled($url) && ! preg_match('/^\s*(?:javascript|data|vbscript):/i', (string) $url) ? (string) $url : null;
@endphp
@if ($items !== [])
<nav {{ $attributes->class(['breadcrumbs']) }} aria-label="{{ $label }}">
    <ol class="breadcrumbs__list">
        @foreach ($items as $i => $item)
            <li class="breadcrumbs__item">
                @if ($i === $last || $safe($item['url'] ?? null) === null)
                    <span @if ($i === $last) aria-current="page" @endif>{{ $item['label'] ?? '' }}</span>
                @else
                    <a href="{{ $safe($item['url']) }}" class="breadcrumbs__link">{{ $item['label'] ?? '' }}</a>
                @endif
                @if ($i !== $last)<x-ui.icon name="chevron-right" :size="14" class="breadcrumbs__sep" />@endif
            </li>
        @endforeach
    </ol>
</nav>
@endif
