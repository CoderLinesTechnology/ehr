<x-layouts.platform title="Edit {{ $organization->name }}">
    @include('platform.partials.assets')
    <x-ui.page-header title="Edit profile" :description="$organization->name">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs :items="[['label' => 'Organizations', 'url' => route('platform.organizations.index')], ['label' => $organization->name, 'url' => route('platform.organizations.show', $organization)], ['label' => 'Edit profile']]" />
        </x-slot:breadcrumbs>
    </x-ui.page-header>

    <form method="POST" action="{{ route('platform.organizations.update', $organization) }}" class="form pf-form" data-submit-once>
        @csrf
        @method('PUT')
        <x-ui.card title="Profile" description="The organization's own settings (branding, formats, scheduling) are managed by the organization itself.">
            @include('platform.organizations._profile-fields', ['creating' => false, 'values' => $organization->only(['name', 'legal_name', 'email', 'phone', 'country_code', 'timezone', 'currency'])])
        </x-ui.card>
        <x-ui.card title="Address and web">
            <div class="form-grid">
                <x-ui.field label="Website" name="website" :optional="true"><x-ui.input type="url" name="website" :value="$organization->website" maxlength="255" placeholder="https://" /></x-ui.field>
                <x-ui.field label="Address line 1" name="address_line1" :optional="true"><x-ui.input name="address_line1" :value="$organization->address_line1" maxlength="200" /></x-ui.field>
                <x-ui.field label="Address line 2" name="address_line2" :optional="true"><x-ui.input name="address_line2" :value="$organization->address_line2" maxlength="200" /></x-ui.field>
                <x-ui.field label="City" name="city" :optional="true"><x-ui.input name="city" :value="$organization->city" maxlength="120" /></x-ui.field>
                <x-ui.field label="Region" name="region" :optional="true"><x-ui.input name="region" :value="$organization->region" maxlength="120" /></x-ui.field>
                <x-ui.field label="Postal code" name="postal_code" :optional="true"><x-ui.input name="postal_code" :value="$organization->postal_code" maxlength="32" /></x-ui.field>
            </div>
        </x-ui.card>
        <div class="form-actions">
            <x-ui.button variant="secondary" :href="route('platform.organizations.show', $organization)">Cancel</x-ui.button>
            <x-ui.button type="submit">Save changes</x-ui.button>
        </div>
    </form>
</x-layouts.platform>
