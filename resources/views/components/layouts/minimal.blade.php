@props([
    'title' => null,
    'width' => 'md',
    'previewShell' => null,
])
{{-- Onboarding, status pages (suspended, pending approval): a header with the wordmark and one centred column. $shell is supplied by the composer. --}}
<x-layouts.bare :title="$title" :width="$width" :shell="$previewShell ?? ($shell ?? [])">{{ $slot }}</x-layouts.bare>
