@props([
    'label' => 'Loading',
    'size' => 20,
])
<span {{ $attributes->class(['spinner']) }} role="status" aria-label="{{ $label }}" style="--spinner-size: {{ (int) $size }}px"></span>