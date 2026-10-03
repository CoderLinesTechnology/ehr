@props([
    'title' => null,
    'description' => null,
    'padded' => true,
    'level' => 2,
])
@php
    $hasActions = isset($actions) && ! $actions->isEmpty();
    $hasFooter = isset($footer) && ! $footer->isEmpty();
    $hasHeader = filled($title) || filled($description) || $hasActions;
    $heading = 'h'.min(max((int) $level, 2), 6);
@endphp
<section {{ $attributes->class(['card']) }}>
    @if ($hasHeader)
        <header class="card__header">
            <div class="card__heading">
                @if (filled($title))<{{ $heading }} class="card__title">{{ $title }}</{{ $heading }}>@endif
                @if (filled($description))<p class="card__description">{{ $description }}</p>@endif
            </div>
            @if ($hasActions)<div class="card__actions">{{ $actions }}</div>@endif
        </header>
    @endif
    <div @class(['card__body', 'card__body--flush' => ! $padded])>{{ $slot }}</div>
    @if ($hasFooter)<footer class="card__footer">{{ $footer }}</footer>@endif
</section>
