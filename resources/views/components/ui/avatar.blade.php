@props([
    'name' => '',
    'src' => null,
    'color' => null,
    'tone' => null,
    'size' => 'md',
    'decorative' => false,
])
@php
    // Sizes: xs 24, sm 32, md 40, lg 56, xl 72, shell 42 (top bar). A photo (src) wins over initials.
    $size = in_array($size, ['xs', 'sm', 'md', 'lg', 'xl', 'shell'], true) ? $size : 'md';
    $parts = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $initials = $parts === []
        ? '?'
        : mb_strtoupper(mb_substr($parts[0], 0, 1).(count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : ''));

    // Only a strict hex colour ever reaches the style attribute (no CSS injection through a caller value).
    $hex = is_string($color) && preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $color) ? strtolower($color) : null;
    $foreground = null;
    if ($hex !== null) {
        $h = strlen($hex) === 4 ? '#'.$hex[1].$hex[1].$hex[2].$hex[2].$hex[3].$hex[3] : $hex;
        $lin = static fn (int $c): float => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        $luminance = 0.2126 * $lin(hexdec(substr($h, 1, 2))) + 0.7152 * $lin(hexdec(substr($h, 3, 2))) + 0.0722 * $lin(hexdec(substr($h, 5, 2)));
        // Pick whichever of near-black / white has the higher contrast against the background.
        $foreground = $luminance > 0.179 ? '#0f1a2e' : '#ffffff';
    }
    // tone="brand" is the solid blue top-bar avatar; otherwise a stable tint derived from the name.
    $palette = ($hex === null && $tone !== 'brand') ? 'c'.(crc32(mb_strtolower(trim((string) $name))) % 8) : null;
    $safeSrc = filled($src) && ! preg_match('/^\s*(?:javascript|data|vbscript):/i', (string) $src) ? (string) $src : null;
@endphp
<span {{ $attributes->class(['avatar', 'avatar--'.$size, 'avatar--'.$palette => $palette !== null, 'avatar--brand' => $tone === 'brand' && $hex === null]) }} @if ($hex !== null) style="--avatar-bg: {{ $hex }}; --avatar-fg: {{ $foreground }}" @endif @if ($decorative) aria-hidden="true" @elseif (filled($name)) role="img" aria-label="{{ $name }}" @endif>@if ($safeSrc !== null)<img src="{{ $safeSrc }}" alt="" class="avatar__img" loading="lazy" decoding="async">@else<span aria-hidden="true">{{ $initials }}</span>@endif</span>
