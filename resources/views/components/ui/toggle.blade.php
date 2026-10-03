@props([
    'name',
    'label' => null,
    'checked' => false,
    'value' => '1',
    'hiddenDefault' => true,
    'id' => null,
    'help' => null,
    'bag' => 'default',
])
@php
    $dot = trim((string) preg_replace('/\[([^\]]*)\]/', '.$1', (string) $name), '.');
    $cid = $id ?: \Illuminate\Support\Str::slug(str_replace('.', '-', $dot));
    $messages = [];
    if (isset($errors)) {
        $errorBag = $errors->getBag($bag);
        $messages = array_values(array_unique($errorBag->get($dot)));
    }
    $request = request();
    $hasOld = $request->hasSession() && $request->session()->hasOldInput($dot);
    $oldValues = array_map('strval', array_filter((array) old($dot), 'is_scalar'));
    $isChecked = $hasOld ? in_array((string) $value, $oldValues, true) : (bool) $checked;
    $hasHelp = filled($help);
    $describedBy = trim((string) $attributes->get('aria-describedby').' '.($hasHelp ? $cid.'-help ' : '').($messages !== [] ? $cid.'-error' : ''));
    $content = filled($label) ? $label : $slot;
@endphp
<div class="toggle-field">
    <label class="toggle" for="{{ $cid }}">
        @if ($hiddenDefault)<input type="hidden" name="{{ $name }}" value="0">@endif
        <input type="checkbox" role="switch" id="{{ $cid }}" name="{{ $name }}" value="{{ $value }}" @checked($isChecked) {{ $attributes->except('aria-describedby')->class(['toggle__input', 'is-invalid' => $messages !== []])->merge(['aria-invalid' => $messages !== [] ? 'true' : null, 'aria-describedby' => $describedBy !== '' ? $describedBy : null]) }}>
        <span class="toggle__track" aria-hidden="true"><span class="toggle__thumb"></span></span>
        <span class="toggle__text">{{ $content }}@if ($hasHelp)<span class="choice__help" id="{{ $cid }}-help">{{ $help }}</span>@endif</span>
    </label>
    @if ($messages !== [])
        <div class="field__error" id="{{ $cid }}-error">
            @foreach ($messages as $message)
                <p class="field__error-item"><x-ui.icon name="alert-triangle" :size="14" /><span>{{ $message }}</span></p>
            @endforeach
        </div>
    @endif
</div>
