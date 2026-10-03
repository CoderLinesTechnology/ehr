@props([
    'icon' => null,
    'tone' => 'default',
    'shape' => 'circle',
    'size' => 40,
    'iconSize' => null,
])
@php
    // Pastel circle / rounded square behind an icon: page headers (56 circle, 60 square), stat cards (36 square / 42 circle), list rows.
    $tones = ['default', 'blue', 'green', 'teal', 'sky', 'purple', 'amber', 'red', 'pink', 'neutral'];
    $tone = in_array($tone, $tones, true) ? $tone : 'default';
    $shape = in_array($shape, ['circle', 'square', 'square-lg', 'outlined'], true) ? $shape : 'circle';
    $px = max(16, min(96, (int) $size));
    $glyph = $iconSize !== null ? (int) $iconSize : (int) round($px * .5);
@endphp
<span {{ $attributes->class(['icon-tile', 'icon-tile--'.$tone => $tone !== 'default', 'icon-tile--square' => $shape === 'square', 'icon-tile--square-lg' => in_array($shape, ['square-lg', 'outlined'], true), 'icon-tile--outlined' => $shape === 'outlined']) }} style="--tile: {{ $px }}px" aria-hidden="true">@if (filled($icon))<x-ui.icon :name="$icon" :size="$glyph" />@endif</span>
