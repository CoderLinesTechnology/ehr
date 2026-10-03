@props([
    'tone' => 'neutral',
    'icon' => null,
    'dot' => false,
])
@php
    // Status colours follow the screens (SPEC decision 7): Active/Confirmed = success, Pending/Scheduled-blue = info,
    // Scheduled/Inactive = neutral. Status is never colour alone: the badge always carries text.
    $tones = ['neutral', 'info', 'pending', 'primary', 'success', 'warning', 'danger', 'demo', 'purple', 'teal', 'pink', 'completed', 'outline', 'tag', 'priority-high', 'priority-medium'];
    $tone = in_array($tone, $tones, true) ? $tone : 'neutral';
    $icon ??= $tone === 'demo' ? 'flask-conical' : null;
@endphp
<span {{ $attributes->class(['badge', 'badge--'.$tone, 'badge--dot' => $dot]) }}>@if ($icon)<x-ui.icon :name="$icon" :size="12" />@endif<span>{{ $slot->isEmpty() && $tone === 'demo' ? 'Demo' : $slot }}</span></span>
