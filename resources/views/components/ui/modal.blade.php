@props([
    'id',
    'title' => null,
    'size' => 'md',
    'open' => false,
    'dismissible' => true,
    'description' => null,
])
@php
    $size = in_array($size, ['sm', 'md', 'lg'], true) ? $size : 'md';
    $hasFooter = isset($footer) && ! $footer->isEmpty();
@endphp
{{-- Native <dialog>: opened by any element with data-dialog-open="id", closed by data-dialog-close or Esc, focus returns to the opener (app.js). open=true re-opens it on page load, e.g. after a validation error. --}}
<dialog id="{{ $id }}" {{ $attributes->class(['modal', 'modal--'.$size]) }} aria-labelledby="{{ $id }}-title" @if (filled($description)) aria-describedby="{{ $id }}-desc" @endif @if ($dismissible) data-dismissible @endif @if ($open) data-open-on-load @endif>
    <div class="modal__panel">
        <header class="modal__header">
            <h2 class="modal__title" id="{{ $id }}-title">{{ $title }}</h2>
            <button type="button" class="icon-btn" data-dialog-close aria-label="Close dialog"><x-ui.icon name="x" :size="18" /></button>
        </header>
        <div class="modal__body">
            @if (filled($description))<p class="modal__description" id="{{ $id }}-desc">{{ $description }}</p>@endif
            {{ $slot }}
        </div>
        @if ($hasFooter)<footer class="modal__footer">{{ $footer }}</footer>@endif
    </div>
</dialog>
