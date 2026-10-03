@php
    $prevDay = $afterDay ?? null;
    $today = fmt()->local(now());
    $dayLabel = function (\Carbon\CarbonImmutable $at) use ($today): string {
        $local = fmt()->local($at);
        return match (true) {
            $local->isSameDay($today) => 'Today',
            $local->isSameDay($today->subDay()) => 'Yesterday',
            default => fmt()->localDate($at),
        };
    };
@endphp
@foreach ($messages as $m)
    @php($day = fmt()->local($m->at)->format('Y-m-d'))
    @if ($day !== $prevDay)
        <div class="msg-day" data-day="{{ $day }}"><span>{{ $dayLabel($m->at) }}</span></div>
        @php($prevDay = $day)
    @endif
    @include('app.messages._message', ['m' => $m])
@endforeach
