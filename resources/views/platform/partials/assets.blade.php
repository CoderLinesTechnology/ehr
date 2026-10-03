@push('styles')
    <link rel="stylesheet" href="{{ asset('css/screens/platform.css') }}?v={{ filemtime(public_path('css/screens/platform.css')) }}">
@endpush
