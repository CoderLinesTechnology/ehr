@props([
    'title' => null,
    'description' => null,
])
<header {{ $attributes->class(['page-header']) }}>
    @if (isset($breadcrumbs) && ! $breadcrumbs->isEmpty())
        <div class="page-header__crumbs">{{ $breadcrumbs }}</div>
    @endif
    <div class="page-header__main">
        <div class="page-header__text">
            <h1 class="page-header__title">{{ $title }}</h1>
            @if (filled($description))<p class="page-header__description">{{ $description }}</p>@endif
            @if (isset($meta) && ! $meta->isEmpty())<div class="page-header__meta">{{ $meta }}</div>@endif
        </div>
        @if (isset($actions) && ! $actions->isEmpty())<div class="page-header__actions">{{ $actions }}</div>@endif
    </div>
</header>
