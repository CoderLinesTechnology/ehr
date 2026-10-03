{{-- Profile fields shared by create and edit. $values: current values; $creating: also show the address (slug) field. --}}
<div class="form-grid">
    <x-ui.field label="Organization name" name="name" :required="true"><x-ui.input name="name" :value="$values['name'] ?? null" required maxlength="160" /></x-ui.field>
    @if ($creating)
        <x-ui.field label="Address" name="slug" :optional="true" help="Lowercase letters, numbers and hyphens. Leave empty to generate it from the name."><x-ui.input name="slug" :value="$values['slug'] ?? null" maxlength="50" autocapitalize="off" /></x-ui.field>
    @endif
    <x-ui.field label="Legal name" name="legal_name" :optional="true"><x-ui.input name="legal_name" :value="$values['legal_name'] ?? null" maxlength="200" /></x-ui.field>
    <x-ui.field label="Contact email" name="email" :optional="true"><x-ui.input type="email" name="email" :value="$values['email'] ?? null" maxlength="254" /></x-ui.field>
    <x-ui.field label="Phone" name="phone" :optional="true"><x-ui.input type="tel" name="phone" :value="$values['phone'] ?? null" maxlength="32" /></x-ui.field>
    <x-ui.field label="Country" name="country_code" :required="true"><x-ui.select name="country_code" :options="$countries" :value="$values['country_code'] ?? null" required /></x-ui.field>
    <x-ui.field label="Timezone" name="timezone" :required="true"><x-ui.select name="timezone" :options="$timezones" :value="$values['timezone'] ?? null" required /></x-ui.field>
    <x-ui.field label="Currency" name="currency" :required="true"><x-ui.select name="currency" :options="$currencies" :value="$values['currency'] ?? null" required /></x-ui.field>
</div>
