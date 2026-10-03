{{-- Client initials in a tint chosen from the client number (stable per client); $name, $number ("CL-0012"), $size = lg|xl. --}}
@php
    $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $initials = $parts === [] ? '?' : mb_strtoupper(mb_substr($parts[0], 0, 1).(count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : ''));
    $tint = [0, 2, 1, 5, 6, 7][((int) preg_replace('/\D/', '', $number)) % 6];
@endphp
<span class="avatar avatar--{{ $size }} avatar--c{{ $tint }} {{ $class ?? '' }}" aria-hidden="true"><span>{{ $initials }}</span></span>
