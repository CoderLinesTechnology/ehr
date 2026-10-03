@php
    $styles = asset('css/screens/programs.css').'?v='.filemtime(public_path('css/screens/programs.css'));
@endphp
<link rel="stylesheet" href="{{ $styles }}">
