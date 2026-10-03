@props([
    'name' => null,
    'size' => 'md',
    'mark' => false,
    'light' => false,
])
@php
    // size: "md" (sidebar, 40x37 box = 38x35 ink + 22px wordmark), "sm" (phone/tablet top bar), or a pixel width for the mark alone.
    $name = filled($name) ? $name : config('app.name', 'WellNest');
    $px = is_numeric($size) ? (int) $size : ($size === 'sm' ? 28 : 40);
    $height = (int) round($px * 49 / 53);
@endphp
{{-- The WellNest two-leaf mark (public/images/wellnest-mark.svg, vectorised from the comps) next to the product wordmark. mark=true shows the symbol alone. --}}
<span {{ $attributes->class(['logo', 'logo--sm' => $size === 'sm', 'logo--light' => $light]) }}>
    <img class="logo__mark" src="{{ asset('images/wellnest-mark.svg') }}" width="{{ $px }}" height="{{ $height }}" alt="" decoding="async">
    @unless ($mark)<span class="logo__name">{{ $name }}</span>@endunless
</span>
