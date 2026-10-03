@props([
    'icon' => 'inbox',
    'title' => null,
    'description' => null,
])
<div {{ $attributes->class(['empty-state']) }}>
    <span class="empty-state__icon"><x-ui.icon :name="$icon" :size="24" /></span>
    @if (filled($title))<h3 class="empty-state__title">{{ $title }}</h3>@endif
    @if (filled($description))<p class="empty-state__description">{{ $description }}</p>@endif
    @if (isset($actions) && ! $actions->isEmpty())<div class="empty-state__actions">{{ $actions }}</div>@endif
</div>
