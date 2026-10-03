@props([
    'id',
    'title' => null,
    'open' => false,
    'dismissible' => true,
    'description' => null,
])
@php
    $hasFooter = isset($footer) && ! $footer->isEmpty();
@endphp
{{-- Same behaviour as the modal; slides in from the right and fills the screen on phones. --}}
<dialog id="{{ $id }}" {{ $attributes->class(['modal', 'drawer']) }} aria-labelledby="{{ $id }}-title" @if (filled($description)) aria-describedby="{{ $id }}-desc" @endif @if ($dismissible) data-dismissible @endif @if ($open) data-open-on-load @endif>
    <div class="modal__panel">
        <header class="modal__header">
            <h2 class="modal__title" id="{{ $id }}-title">{{ $title }}</h2>
            <button type="button" class="icon-btn" data-dialog-close aria-label="Close panel"><x-ui.icon name="x" :size="18" /></button>
        </header>
        <div class="modal__body">
            @if (filled($description))<p class="modal__description" id="{{ $id }}-desc">{{ $description }}</p>@endif
            {{ $slot }}
        </div>
        @if ($hasFooter)<footer class="modal__footer">{{ $footer }}</footer>@endif
    </div>
</dialog>
