{{-- One e-mail / phone row of the client form. Needs: $kind (email|phone), $key, $row (value, label), $primary (key of the primary row), $labels, $noun, $spareRow. --}}
@php
    $base = $kind.'s['.$key.']';
    $id = $kind.'-'.$key;
@endphp
<div class="point-row" data-repeat-row @if ($spareRow) data-repeat-spare @endif>
    <x-ui.field :label="$noun" :name="$base.'[value]'" :id="$id.'-value'" class="point-row__value">
        <x-ui.input :type="$kind === 'email' ? 'email' : 'tel'" :name="$base.'[value]'" :id="$id.'-value'" :value="$row['value'] ?? null" :maxlength="$kind === 'email' ? 254 : 32" autocomplete="off" />
    </x-ui.field>
    <x-ui.field label="Type" :name="$base.'[label]'" :id="$id.'-label'" class="point-row__label">
        <x-ui.select :name="$base.'[label]'" :id="$id.'-label'" :value="$row['label'] ?? ($kind === 'phone' ? 'mobile' : 'home')" :options="$labels" />
    </x-ui.field>
    <label class="point-row__primary">
        <input type="radio" name="primary_{{ $kind }}" value="{{ $key }}" @checked($primary === (string) $key)>
        <span>Primary</span>
    </label>
    <button type="button" class="point-row__remove" data-repeat-remove hidden aria-label="Remove this {{ strtolower($noun) }}"><x-ui.icon name="x" :size="16" /></button>
</div>
