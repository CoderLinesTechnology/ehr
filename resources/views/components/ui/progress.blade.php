@props([
    'value' => 0,
    'max' => 100,
    'label' => null,
    'showValue' => true,
])
@php
    $max = max(1, (int) $max);
    $current = min(max((int) $value, 0), $max);
    $percent = (int) round($current / $max * 100);
    $text = $current.' of '.$max;
@endphp
<div {{ $attributes->class(['progress']) }}>
    @if (filled($label) || $showValue)
        <div class="progress__head">
            @if (filled($label))<span class="progress__label">{{ $label }}</span>@endif
            @if ($showValue)<span class="progress__value">{{ $text }}</span>@endif
        </div>
    @endif
    <div class="progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $max }}" aria-valuenow="{{ $current }}" aria-valuetext="{{ $text }}" @if (filled($label)) aria-label="{{ $label }}" @endif>
        <span class="progress__bar" style="width: {{ $percent }}%"></span>
    </div>
</div>
