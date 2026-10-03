@props([
    'label' => 'Actions',
    'icon' => null,
    'align' => 'left',
    'showLabel' => false,
    'variant' => 'secondary',
    'size' => 'md',
])
@php
    $align = $align === 'right' ? 'right' : 'left';
    $custom = isset($trigger) && ! $trigger->isEmpty();
    // An icon with no visible label is an icon button; its accessible name is the label.
    $iconOnly = ! $custom && filled($icon) && ! $showLabel;
    $iconSize = $size === 'sm' ? 16 : 18;
@endphp
{{-- A <details> disclosure: opens and closes without JavaScript. app.js adds Esc, arrow keys, outside-click and viewport-aware placement. --}}
<details {{ $attributes->class(['dropdown', 'dropdown--'.$align]) }}>
    @if ($custom)
        <summary class="dropdown__trigger">{{ $trigger }}</summary>
    @elseif ($iconOnly)
        <summary @class(['btn', 'btn--ghost', 'btn--icon', 'btn--'.$size => $size !== 'md']) aria-label="{{ $label }}"><x-ui.icon :name="$icon" :size="$iconSize" /></summary>
    @else
        <summary @class(['btn', 'btn--'.$variant, 'btn--'.$size => $size !== 'md'])>
            @if (filled($icon))<x-ui.icon :name="$icon" :size="$iconSize" />@endif
            <span class="btn__label">{{ $label }}</span>
            <x-ui.icon name="chevron-down" :size="$iconSize" class="dropdown__caret" />
        </summary>
    @endif
    <div class="dropdown__menu">{{ $slot }}</div>
</details>
