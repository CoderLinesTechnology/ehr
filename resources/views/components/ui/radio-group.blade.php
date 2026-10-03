@props([
    'name',
    'options' => [],
    'value' => null,
    'id' => null,
    'bag' => 'default',
    'inline' => false,
])
@php
    // Options are [value => label] or [value => ['label' => '…', 'description' => '…']].
    $dot = trim((string) preg_replace('/\[([^\]]*)\]/', '.$1', (string) $name), '.');
    $cid = $id ?: \Illuminate\Support\Str::slug(str_replace('.', '-', $dot));
    $invalid = isset($errors) && $errors->getBag($bag)->has($dot);
    $current = old($dot, $value);
    $current = is_scalar($current) ? (string) $current : '';
@endphp
<div @class(['option-list', 'option-list--inline' => $inline]) data-choice-group="1">
    @foreach ($options as $optionValue => $option)
        @php
            $optionLabel = is_array($option) ? ($option['label'] ?? $optionValue) : $option;
            $optionHelp = is_array($option) ? ($option['description'] ?? null) : null;
            $rid = $cid.'-'.\Illuminate\Support\Str::slug((string) $optionValue);
        @endphp
        <div class="choice">
            <input type="radio" id="{{ $rid }}" name="{{ $name }}" value="{{ $optionValue }}" @checked((string) $optionValue === $current) @if (filled($optionHelp)) aria-describedby="{{ $rid }}-help" @endif {{ $attributes->class(['choice__input', 'is-invalid' => $invalid]) }}>
            <label for="{{ $rid }}" class="choice__label">{{ $optionLabel }}@if (filled($optionHelp))<span class="choice__help" id="{{ $rid }}-help">{{ $optionHelp }}</span>@endif</label>
        </div>
    @endforeach
</div>
