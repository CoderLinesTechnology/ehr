@props([
    'name' => '',
    'color' => null,
    'size' => 'md',
    'decorative' => false,
])
@php
    $size = in_array($size, ['sm', 'md', 'lg', 'xl'], true) ? $size : 'md';
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
    $palette = $hex === null ? 'c'.(crc32(mb_strtolower(trim((string) $name))) % 8) : null;
@endphp
<span {{ $attributes->class(['avatar', 'avatar--'.$size, 'avatar--'.$palette => $palette !== null]) }} @if ($hex !== null) style="--avatar-bg: {{ $hex }}; --avatar-fg: {{ $foreground }}" @endif @if ($decorative) aria-hidden="true" @elseif (filled($name)) role="img" aria-label="{{ $name }}" @endif><span aria-hidden="true">{{ $initials }}</span></span>