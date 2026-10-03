@props([
    'action' => null,
    'label' => 'Filters',
])
{{-- Pass an action to have the bar render its own GET form; otherwise wrap it in your own form. Controls marked data-autosubmit submit on change (app.js); without JavaScript the Apply button does it. --}}
@if (filled($action))<form method="GET" action="{{ $action }}" {{ $attributes->class(['filter-bar']) }} role="group" aria-label="{{ $label }}">@else<div {{ $attributes->class(['filter-bar']) }} role="group" aria-label="{{ $label }}">@endif
    {{ $slot }}
    <noscript><button type="submit" class="btn btn--secondary">Apply filters</button></noscript>
@if (filled($action))</form>@else</div>@endif
