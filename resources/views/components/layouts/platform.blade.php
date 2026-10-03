@props([
    'title' => null,
    'wide' => false,
    'previewShell' => null,
])
{{-- Super Admin console layout: the same frame with the dark navy sidebar (SPEC decision 3), so an operator always knows which context they are in. --}}
@php($variant = 'platform')
@include('components.layouts.partials.shell')
