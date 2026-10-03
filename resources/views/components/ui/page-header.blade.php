@props([
    'title' => null,
    'description' => null,
    'icon' => null,
    'iconShape' => 'circle',
    'iconTone' => 'default',
])
@php
    // icon + iconShape: "circle" (56px, Clients/Settings) or "square" (60px rounded-16 outlined tile, Appointments).
    $square = $iconShape === 'square';
@endphp
<header {{ $attributes->class(['page-header']) }}>
    @if (isset($breadcrumbs) && ! $breadcrumbs->isEmpty())
        <div class="page-header__crumbs">{{ $breadcrumbs }}</div>
    @endif
    <div class="page-header__main">
        <div class="page-header__lead">
            @if (filled($icon))
                <x-ui.icon-tile class="page-header__tile" :icon="$icon" :tone="$iconTone" :shape="$square ? 'outlined' : 'circle'" :size="$square ? 60 : 56" :icon-size="$square ? 30 : 28" />
            @endif
            <div class="page-header__text">
                <h1 class="page-header__title">{{ $title }}</h1>
                @if (filled($description))<p class="page-header__description">{{ $description }}</p>@endif
                @if (isset($meta) && ! $meta->isEmpty())<div class="page-header__meta">{{ $meta }}</div>@endif
            </div>
        </div>
        @if (isset($actions) && ! $actions->isEmpty())<div class="page-header__actions">{{ $actions }}</div>@endif
    </div>
</header>
