@props([
    'code' => null,
    'title' => null,
    'message' => null,
    'icon' => 'info',
])
{{-- A centred, narrow status or error block. Put the buttons in the default slot. Never pass exception detail into it. --}}
<div {{ $attributes->class(['status-page']) }}>
    <span class="status-page__icon"><x-ui.icon :name="$icon" :size="28" /></span>
    @if ($code !== null)<p class="status-page__code">Error {{ $code }}</p>@endif
    <h1 class="status-page__title">{{ $title }}</h1>
    @if (filled($message))<p class="status-page__text">{{ $message }}</p>@endif
    @if (! $slot->isEmpty())<div class="status-page__actions">{{ $slot }}</div>@endif
</div>
