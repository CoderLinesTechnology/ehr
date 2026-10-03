@props([
    'label' => null,
    'name' => null,
    'required' => false,
    'optional' => false,
    'help' => null,
    'id' => null,
    'bag' => 'default',
    'group' => null,
])
@php
    // settings[timezone] -> settings.timezone ; tags[] -> tags
    $dot = trim((string) preg_replace('/\[([^\]]*)\]/', '.$1', (string) $name), '.');
    $fid = $id ?: \Illuminate\Support\Str::slug(str_replace('.', '-', $dot));
    $messages = [];
    if ($dot !== '' && isset($errors)) {
        $errorBag = $errors->getBag($bag);
        $messages = array_values(array_unique(array_merge($errorBag->get($dot), $errorBag->get($dot.'.*'))));
    }
    // Radio / checkbox lists announce themselves with data-choice-group and need fieldset + legend.
    $isGroup = $group ?? str_contains((string) $slot, 'data-choice-group');
    $tag = $isGroup ? 'fieldset' : 'div';
    $labelTag = $isGroup ? 'legend' : 'label';
    $hasHelp = filled($help);
@endphp
<{{ $tag }} {{ $attributes->class(['field']) }} @if ($isGroup) aria-describedby="{{ $fid }}-help {{ $fid }}-error" @endif>
    @if (filled($label))
        <{{ $labelTag }} class="field__label" @if (! $isGroup) for="{{ $fid }}" @endif>
            {{ $label }}
            @if ($required)
                <span class="field__req" aria-hidden="true">*</span><span class="sr-only">(required)</span>
            @elseif ($optional)
                <span class="field__opt">(optional)</span>
            @endif
        </{{ $labelTag }}>
    @endif
    {{ $slot }}
    <div class="field__error" id="{{ $fid }}-error" @if ($messages === []) hidden @endif>
        @foreach ($messages as $message)
            <p class="field__error-item"><x-ui.icon name="alert-triangle" :size="14" /><span>{{ $message }}</span></p>
        @endforeach
    </div>
    <p class="field__help" id="{{ $fid }}-help" @if (! $hasHelp) hidden @endif>{{ $help }}</p>
</{{ $tag }}>
