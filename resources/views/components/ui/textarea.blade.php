@props([
    'name',
    'value' => null,
    'rows' => 4,
    'id' => null,
    'bag' => 'default',
])
@php
    $dot = trim((string) preg_replace('/\[([^\]]*)\]/', '.$1', (string) $name), '.');
    $cid = $id ?: \Illuminate\Support\Str::slug(str_replace('.', '-', $dot));
    $invalid = isset($errors) && $errors->getBag($bag)->has($dot);
    $current = old($dot, $value);
    $current = is_scalar($current) ? (string) $current : '';
    $describedBy = trim((string) $attributes->get('aria-describedby').' '.$cid.'-help '.$cid.'-error');
@endphp
<textarea name="{{ $name }}" id="{{ $cid }}" rows="{{ (int) $rows }}" {{ $attributes->except('aria-describedby')->class(['input', 'textarea', 'is-invalid' => $invalid])->merge(['aria-invalid' => $invalid ? 'true' : null, 'aria-describedby' => $describedBy]) }}>{{ $current }}</textarea>
