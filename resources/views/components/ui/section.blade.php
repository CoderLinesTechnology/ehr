@props([
    'title' => null,
    'description' => null,
])
{{-- Settings-page section: the heading and explanation sit on the left, the controls on the right; stacked on narrow screens. --}}
<section {{ $attributes->class(['section']) }}>
    <div class="section__intro">
        @if (filled($title))<h2 class="section__title">{{ $title }}</h2>@endif
        @if (filled($description))<p class="section__description">{{ $description }}</p>@endif
    </div>
    <div class="section__content">{{ $slot }}</div>
</section>
