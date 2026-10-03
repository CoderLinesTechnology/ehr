@props([
    'name',
    'options' => [],
    'value' => null,
    'placeholder' => null,
    'id' => null,
    'bag' => 'default',
    'multiple' => false,
])
@php
    $dot = trim((string) preg_replace('/\[([^\]]*)\]/', '.$1', (string) $name), '.');
    $cid = $id ?: \Illuminate\Support\Str::slug(str_replace('.', '-', $dot));
    $invalid = false;
    if (isset($errors)) {
        $errorBag = $errors->getBag($bag);
        $invalid = $errorBag->has($dot) || $errorBag->has($dot.'.*');
    }
    $current = old($dot, $value);
    $selected = array_map(
        static fn ($v) => is_bool($v) ? ($v ? '1' : '0') : (string) $v,
        array_values(array_filter(is_array($current) ? $current : [$current], static fn ($v) => $v !== null && is_scalar($v)))
    );
    $describedBy = trim((string) $attributes->get('aria-describedby').' '.$cid.'-help '.$cid.'-error');
@endphp
<div class="select @if ($multiple) select--multiple @endif">
    <select name="{{ $name }}" id="{{ $cid }}" @if ($multiple) multiple @endif {{ $attributes->except('aria-describedby')->class(['input', 'select__control', 'is-invalid' => $invalid])->merge(['aria-invalid' => $invalid ? 'true' : null, 'aria-describedby' => $describedBy]) }}>
        @if ($placeholder !== null && ! $multiple)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $key => $label)
            @if (is_array($label))
                <optgroup label="{{ $key }}">
                    @foreach ($label as $optionValue => $optionLabel)
                        <option value="{{ $optionValue }}" @selected(in_array((string) $optionValue, $selected, true))>{{ $optionLabel }}</option>
                    @endforeach
                </optgroup>
            @else
                <option value="{{ $key }}" @selected(in_array((string) $key, $selected, true))>{{ $label }}</option>
            @endif
        @endforeach
    </select>
    @unless ($multiple)<x-ui.icon name="chevron-down" :size="16" class="select__icon" />@endunless
</div>
