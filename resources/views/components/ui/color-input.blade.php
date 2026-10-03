@props([
    'name',
    'value' => null,
    'id' => null,
    'bag' => 'default',
])
@php
    $dot = trim((string) preg_replace('/\[([^\]]*)\]/', '.$1', (string) $name), '.');
    $cid = $id ?: \Illuminate\Support\Str::slug(str_replace('.', '-', $dot));
    $invalid = isset($errors) && $errors->getBag($bag)->has($dot);
    $current = old($dot, $value);
    $current = is_string($current) ? $current : '';
    $valid = (bool) preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $current);
    // <input type="color"> only takes #rrggbb.
    $swatch = $valid ? (strlen($current) === 4 ? '#'.$current[1].$current[1].$current[2].$current[2].$current[3].$current[3] : $current) : '#ffffff';
    $describedBy = trim((string) $attributes->get('aria-describedby').' '.$cid.'-help '.$cid.'-error');
@endphp
<div class="color-input" data-color-input>
    {{-- The text box is the real field (works without JavaScript); the swatch is a convenience that app.js reveals and keeps in sync. --}}
    <input type="color" class="color-input__picker" value="{{ strtolower($swatch) }}" aria-label="Pick a colour" data-color-picker hidden>
    <input type="text" name="{{ $name }}" id="{{ $cid }}" value="{{ $current }}" maxlength="7" pattern="#[0-9a-fA-F]{6}" placeholder="#5b8def" autocomplete="off" spellcheck="false" data-color-text {{ $attributes->except('aria-describedby')->class(['input', 'color-input__text', 'is-invalid' => $invalid])->merge(['aria-invalid' => $invalid ? 'true' : null, 'aria-describedby' => $describedBy]) }}>
</div>
