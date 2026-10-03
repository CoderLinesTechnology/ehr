@props([
    'title' => null,
    'wide' => false,
    'previewShell' => null,
])
{{-- Staff application layout. The frame is shared with the platform layout (partials/shell). --}}
@php($variant = 'app')
@include('components.layouts.partials.shell')
