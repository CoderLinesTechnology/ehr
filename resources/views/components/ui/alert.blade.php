@props([
    'tone' => 'info',
    'title' => null,
    'dismissible' => false,
])
@php
    $tone = in_array($tone, ['info', 'success', 'warning', 'danger'], true) ? $tone : 'info';
    // Problems interrupt (role=alert); confirmations and hints are polite (role=status).
    $role = in_array($tone, ['warning', 'danger'], true) ? 'alert' : 'status';
    $icon = ['info' => 'info', 'success' => 'check-circle', 'warning' => 'alert-triangle', 'danger' => 'x-circle'][$tone];
@endphp
<div {{ $attributes->class(['alert', 'alert--'.$tone]) }} role="{{ $role }}">
    <x-ui.icon :name="$icon" :size="20" class="alert__icon" />
    <div class="alert__content">
        @if (filled($title))<p class="alert__title">{{ $title }}</p>@endif
        @if (! $slot->isEmpty())<div class="alert__body">{{ $slot }}</div>@endif
    </div>
    @if ($dismissible)
        {{-- Hidden until app.js runs: without JavaScript the message simply stays. --}}
        <button type="button" class="alert__dismiss" data-dismiss hidden aria-label="Dismiss message"><x-ui.icon name="x" :size="16" /></button>
    @endif
</div>
