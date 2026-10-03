{{-- One parent / guardian row of the client form (a minor). Needs: $key, $row, $spareRow; $guardianTypes from the form data. --}}
@php
    $base = 'guardians['.$key.']';
    $id = 'guardian-'.$key;
@endphp
<div class="guardian-row" data-repeat-row @if ($spareRow) data-repeat-spare @endif>
    <div class="form-grid">
        <x-ui.field label="Name" :name="$base.'[name]'" :id="$id.'-name'" :required="true"><x-ui.input :name="$base.'[name]'" :id="$id.'-name'" :value="$row['name'] ?? null" maxlength="150" autocomplete="off" /></x-ui.field>
        <x-ui.field label="Relationship" :name="$base.'[relationship_type]'" :id="$id.'-type'"><x-ui.select :name="$base.'[relationship_type]'" :id="$id.'-type'" :value="$row['relationship_type'] ?? 'parent'" :options="$guardianTypes" /></x-ui.field>
        <x-ui.field label="Phone" :name="$base.'[phone]'" :id="$id.'-phone'"><x-ui.input type="tel" :name="$base.'[phone]'" :id="$id.'-phone'" :value="$row['phone'] ?? null" maxlength="32" autocomplete="off" /></x-ui.field>
        <x-ui.field label="Email" :name="$base.'[email]'" :id="$id.'-email'"><x-ui.input type="email" :name="$base.'[email]'" :id="$id.'-email'" :value="$row['email'] ?? null" maxlength="254" autocomplete="off" /></x-ui.field>
    </div>
    <div class="guardian-row__foot">
        <x-ui.checkbox :name="$base.'[is_emergency_contact]'" :id="$id.'-emergency'" value="1" :checked="filter_var($row['is_emergency_contact'] ?? false, FILTER_VALIDATE_BOOL)" label="Also an emergency contact" />
        <button type="button" class="guardian-row__remove" data-repeat-remove hidden><x-ui.icon name="trash-2" :size="14" /><span>Remove</span></button>
    </div>
</div>
