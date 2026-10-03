@props([
    'label' => null,
    'wide' => false,
])
<div {{ $attributes->class(['dl__item', 'dl__item--wide' => $wide]) }}>
    <dt class="dl__term">{{ $label }}</dt>
    <dd class="dl__value">@if ($slot->isEmpty())<span class="dl__empty" aria-hidden="true">&mdash;</span><span class="sr-only">Not provided</span>@else{{ $slot }}@endif</dd>
</div>
