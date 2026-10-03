@props([
    'tone' => 'neutral',
    'icon' => null,
])
@php
    $tone = in_array($tone, ['neutral', 'info', 'primary', 'success', 'warning', 'danger', 'demo'], true) ? $tone : 'neutral';
    // Demo records must never be mistaken for real ones: striped amber, flask icon and the word, never colour alone.
    $icon ??= $tone === 'demo' ? 'flask' : null;
@endphp
<span {{ $attributes->class(['badge', 'badge--'.$tone]) }}>@if ($icon)<x-ui.icon :name="$icon" :size="12" />@endif<span>@if ($slot->isEmpty() && $tone === 'demo')Demo@else{{ $slot }}@endif</span></span>