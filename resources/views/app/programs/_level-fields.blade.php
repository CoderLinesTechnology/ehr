@php $v = fn (string $f, $d = null) => $level?->{$f} ?? $d; $id = 'lv-'.($level?->id ?? 'new').'-'; @endphp
<div class="pr-form__grid">
    <x-ui.field label="Name" name="name" :id="$id.'name'" :required="true"><x-ui.input name="name" :id="$id.'name'" :value="$v('name')" maxlength="80" placeholder="Level I – Outpatient" required /></x-ui.field>
    <x-ui.field label="Order" name="sort" :id="$id.'sort'" :optional="true" help="Lower numbers come first; the first is the card's tag."><x-ui.input type="number" name="sort" :id="$id.'sort'" :value="$v('sort', 0)" min="0" max="65535" /></x-ui.field>
    <x-ui.field class="pr-form__full" label="Description" name="description" :id="$id.'description'" :optional="true"><x-ui.textarea name="description" :id="$id.'description'" :value="$v('description')" rows="2" maxlength="1000" /></x-ui.field>
    <x-ui.field class="pr-form__full" label="Eligibility" name="eligibility" :id="$id.'eligibility'" :optional="true"><x-ui.textarea name="eligibility" :id="$id.'eligibility'" :value="$v('eligibility')" rows="2" maxlength="1000" /></x-ui.field>
    <div class="pr-form__full"><x-ui.checkbox name="is_active" :id="$id.'active'" label="Offered to new admissions" :checked="$level?->is_active ?? true" /></div>
</div>
