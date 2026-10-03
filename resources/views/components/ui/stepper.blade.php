@props([
    'steps' => [],
    'current' => 1,
    'label' => 'Progress',
])
{{-- Numbered steps (sheet §10). $steps is a list of labels; $current is 1-based. Done steps show a check, never colour alone. --}}
<ol {{ $attributes->class(['stepper']) }} aria-label="{{ $label }}">
    @foreach (array_values($steps) as $i => $step)
        @php($n = $i + 1)
        <li @class(['stepper__item', 'is-done' => $n < $current, 'is-current' => $n === (int) $current]) @if ($n === (int) $current) aria-current="step" @endif>
            <span class="stepper__dot">@if ($n < $current)<x-ui.icon name="check" :size="14" :stroke="2.5" />@else{{ $n }}@endif</span>
            <span class="stepper__label">{{ $step }}</span>
        </li>
    @endforeach
</ol>
