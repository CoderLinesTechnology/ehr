@props([
    'title' => null,
    'wide' => false,
    'previewShell' => null,
])
{{-- Super Admin console layout: same frame, graphite sidebar with a violet accent and a "Platform console" label, so an operator always knows which context they are in. --}}
@php($variant = 'platform')
@include('components.layouts.partials.shell')
