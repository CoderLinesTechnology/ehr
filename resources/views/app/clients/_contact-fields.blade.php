{{-- Needs: $contact (ClientContact|null), $country. Phone is shown as typed/stored; the domain normalises it. --}}
<div class="form-grid">
    <x-ui.field label="Name" name="name" :required="true"><x-ui.input name="name" :value="$contact?->name" maxlength="150" autocomplete="off" /></x-ui.field>
    <x-ui.field label="Relationship" name="relationship_type" :optional="true" help="Parent or guardian counts as a minor's guardian."><x-ui.select name="relationship_type" :value="$contact?->relationship_type?->value" placeholder="Not specified" :options="\App\Domain\Clients\RelationshipType::options()" /></x-ui.field>
    <x-ui.field label="Relationship label" name="relationship" :optional="true" help="How it reads in the list, for example “Mother” or “Aunt”."><x-ui.input name="relationship" :value="$contact?->relationship" maxlength="60" placeholder="Mother, spouse, friend…" /></x-ui.field>
    <x-ui.field label="Phone" name="phone" :optional="true"><x-ui.input type="tel" name="phone" :value="$contact?->phone ? \App\Support\PhoneNumbers::display($contact->phone, $country) : null" maxlength="32" autocomplete="off" /></x-ui.field>
    <x-ui.field label="Email" name="email" :optional="true"><x-ui.input type="email" name="email" :value="$contact?->email" maxlength="254" autocomplete="off" /></x-ui.field>
    <x-ui.field label="Notes" name="notes" :optional="true" class="form-grid__full"><x-ui.textarea name="notes" :value="$contact?->notes" rows="2" maxlength="500" /></x-ui.field>
</div>
<x-ui.checkbox name="is_emergency_contact" value="1" :checked="(bool) $contact?->is_emergency_contact" label="This is an emergency contact" />
