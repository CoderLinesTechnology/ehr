@props([
    'name' => null,
    'size' => 28,
    'mark' => false,
])
@php
    $name = filled($name) ? $name : config('app.name', 'Carebase');
@endphp
{{-- Wordmark: a rounded mark (a plus, for care) next to the product name. mark=true shows the symbol alone. --}}
<span {{ $attributes->class(['logo']) }}>
    <svg class="logo__mark" xmlns="http://www.w3.org/2000/svg" width="{{ (int) $size }}" height="{{ (int) $size }}" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
        <rect width="32" height="32" rx="9" fill="var(--logo-bg, #2354c8)"/>
        <path d="M16 8v16M8 16h16" stroke="#fff" stroke-width="3.2" stroke-linecap="round"/>
        <circle cx="16" cy="16" r="3.1" fill="var(--logo-bg, #2354c8)"/>
        <circle cx="16" cy="16" r="1.5" fill="#fff"/>
    </svg>
    @unless ($mark)<span class="logo__name">{{ $name }}</span>@endunless
</span>
