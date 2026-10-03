@props([
    'month' => null,
    'selected' => null,
    'today' => null,
    'marked' => [],
    'href' => null,
    'prevUrl' => null,
    'nextUrl' => null,
])
@php
    // Generic month grid (sheet §15). month: 'Y-m' (defaults to the selected day or the first of this month, in UTC).
    // href: a URL template with {date}; days become links. marked: list of 'Y-m-d' that get a dot. Weeks start on Sunday.
    $parse = static fn (?string $d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $d, 'UTC') : null;
    $first = (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) ? \Carbon\CarbonImmutable::createFromFormat('!Y-m', $month, 'UTC') : null)
        ?? ($parse($selected)?->startOfMonth())
        ?? \Carbon\CarbonImmutable::now('UTC')->startOfMonth();
    $gridStart = $first->subDays($first->dayOfWeek);
    $marked = array_flip((array) $marked);
    $safe = static fn ($url) => filled($url) && ! preg_match('/^\s*(?:javascript|data|vbscript):/i', (string) $url) ? (string) $url : null;
    $dayUrl = static fn (string $date) => $href !== null && $safe($href) !== null ? str_replace('{date}', $date, (string) $href) : null;
@endphp
<div {{ $attributes->class(['card', 'mini-calendar']) }}>
    <div class="mini-calendar__head">
        @if ($safe($prevUrl))<a href="{{ $safe($prevUrl) }}" class="mini-calendar__nav" aria-label="Previous month"><x-ui.icon name="chevron-left" :size="14" /></a>@else<span class="mini-calendar__nav"></span>@endif
        <h2 class="mini-calendar__title">{{ $first->format('F Y') }}</h2>
        @if ($safe($nextUrl))<a href="{{ $safe($nextUrl) }}" class="mini-calendar__nav" aria-label="Next month"><x-ui.icon name="chevron-right" :size="14" /></a>@else<span class="mini-calendar__nav"></span>@endif
    </div>
    <div class="mini-calendar__grid" role="grid" aria-label="{{ $first->format('F Y') }}">
        @foreach (['S', 'M', 'T', 'W', 'T', 'F', 'S'] as $dow)<span class="mini-calendar__dow" aria-hidden="true">{{ $dow }}</span>@endforeach
        @for ($i = 0; $i < 42; $i++)
            @php
                $day = $gridStart->addDays($i);
                $date = $day->format('Y-m-d');
                $url = $dayUrl($date);
                $classes = ['mini-calendar__day', 'is-outside' => $day->month !== $first->month, 'is-today' => $date === $today, 'is-selected' => $date === $selected, 'is-marked' => isset($marked[$date])];
            @endphp
            @if ($url !== null)
                <a href="{{ $url }}" @class($classes) @if ($date === $selected) aria-current="date" @endif aria-label="{{ $day->format('F j, Y') }}">{{ $day->day }}</a>
            @else
                <span @class($classes)>{{ $day->day }}</span>
            @endif
        @endfor
    </div>
</div>
