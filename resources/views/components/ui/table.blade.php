@props([
    'striped' => false,
    'compact' => false,
    'label' => null,
])
{{-- The wrapper scrolls sideways on narrow screens. tabindex=0 keeps it keyboard-scrollable; app.js drops it again when nothing overflows. --}}
<div {{ $attributes->class(['table-wrap']) }} data-table-scroll tabindex="0" @if (filled($label)) role="region" aria-label="{{ $label }}" @endif>
    <table @class(['table', 'table--striped' => $striped, 'table--compact' => $compact])>{{ $slot }}</table>
</div>
