@props([
    'name',
    'type' => 'text',
    'value' => null,
    'id' => null,
    'bag' => 'default',
    'icon' => null,
    'toggle' => null,
])
@php
    $dot = trim((string) preg_replace('/\[([^\]]*)\]/', '.$1', (string) $name), '.');
    $cid = $id ?: \Illuminate\Support\Str::slug(str_replace('.', '-', $dot));
    $invalid = false;
    if (isset($errors)) {
        $errorBag = $errors->getBag($bag);
        $invalid = $errorBag->has($dot) || $errorBag->has($dot.'.*');
    }
    $isPassword = $type === 'password';
    // Secrets and files are never echoed back into the page.
    $current = ($isPassword || $type === 'file') ? null : old($dot, $value);
    $current = is_scalar($current) ? (string) $current : null;
    $showToggle = $toggle ?? $isPassword;
    $wrap = $showToggle || filled($icon);
    $describedBy = trim((string) $attributes->get('aria-describedby').' '.$cid.'-help '.$cid.'-error');
@endphp
@if ($wrap)<div class="input-wrap @if (filled($icon)) input-wrap--icon @endif @if ($showToggle) input-wrap--toggle @endif">
    @if (filled($icon))<x-ui.icon :name="$icon" :size="18" class="input-wrap__icon" />@endif
@endif
<input type="{{ $type }}" name="{{ $name }}" id="{{ $cid }}" @if ($current !== null) value="{{ $current }}" @endif {{ $attributes->except('aria-describedby')->class(['input', 'is-invalid' => $invalid])->merge(['aria-invalid' => $invalid ? 'true' : null, 'aria-describedby' => $describedBy]) }}>
@if ($wrap)
    @if ($showToggle)
        <button type="button" class="input-wrap__toggle" data-password-toggle hidden aria-controls="{{ $cid }}" aria-pressed="false" aria-label="Show password">
            <span data-icon-show><x-ui.icon name="eye" :size="18" /></span><span data-icon-hide hidden><x-ui.icon name="eye-off" :size="18" /></span>
        </button>
    @endif
</div>
@endif
