{{-- Create / edit form body. Needs: $client (Client|null), $clinicians, $locations, $sexOptions, $contactMethods, $countries, $country. --}}
@php
    $v = static fn (string $key) => $client?->{$key};
    $phone = $client?->phone ? \App\Support\PhoneNumbers::display($client->phone, $country) : null;
@endphp
<div class="client-form">
    <x-ui.card title="Personal details">
        <div class="form-grid">
            <x-ui.field label="First name" name="first_name" :required="true"><x-ui.input name="first_name" :value="$v('first_name')" maxlength="100" autocomplete="off" /></x-ui.field>
            <x-ui.field label="Last name" name="last_name" :required="true"><x-ui.input name="last_name" :value="$v('last_name')" maxlength="100" autocomplete="off" /></x-ui.field>
            <x-ui.field label="Middle name" name="middle_name" :optional="true"><x-ui.input name="middle_name" :value="$v('middle_name')" maxlength="100" autocomplete="off" /></x-ui.field>
            <x-ui.field label="Preferred name" name="preferred_name" :optional="true" help="Used in lists and greetings instead of the first name."><x-ui.input name="preferred_name" :value="$v('preferred_name')" maxlength="100" autocomplete="off" /></x-ui.field>
            <x-ui.field label="Date of birth" name="date_of_birth" :optional="! ($dobRequired ?? false)" :required="$dobRequired ?? false"><x-ui.input type="date" name="date_of_birth" :value="$client?->date_of_birth?->format('Y-m-d')" min="1900-01-01" :max="now()->format('Y-m-d')" /></x-ui.field>
            <x-ui.field label="Sex" name="sex" :optional="true"><x-ui.select name="sex" :value="$v('sex')" placeholder="Not recorded" :options="$sexOptions" /></x-ui.field>
            <x-ui.field label="Gender identity" name="gender_identity" :optional="true"><x-ui.input name="gender_identity" :value="$v('gender_identity')" maxlength="60" autocomplete="off" /></x-ui.field>
            <x-ui.field label="Pronouns" name="pronouns" :optional="true"><x-ui.input name="pronouns" :value="$v('pronouns')" maxlength="40" placeholder="she/her, he/him, they/them" autocomplete="off" /></x-ui.field>
        </div>
    </x-ui.card>

    <x-ui.card title="Contact" description="{{ ($contactRequired ?? false) ? 'An email address or a phone number is required.' : 'How the practice reaches this client.' }}">
        <div class="form-grid">
            <x-ui.field label="Phone" name="phone" :optional="true" help="Include the country code, for example +233 24 410 0001."><x-ui.input type="tel" name="phone" :value="$phone" maxlength="32" autocomplete="off" /></x-ui.field>
            <x-ui.field label="Email" name="email" :optional="true"><x-ui.input type="email" name="email" :value="$v('email')" maxlength="254" autocomplete="off" /></x-ui.field>
            <x-ui.field label="Preferred contact method" name="preferred_contact_method" :optional="true"><x-ui.select name="preferred_contact_method" :value="$v('preferred_contact_method')" placeholder="No preference" :options="$contactMethods" /></x-ui.field>
        </div>
    </x-ui.card>

    <x-ui.card title="Address">
        <div class="form-grid">
            <x-ui.field label="Address line 1" name="address_line1" :optional="true"><x-ui.input name="address_line1" :value="$v('address_line1')" maxlength="200" autocomplete="off" /></x-ui.field>
            <x-ui.field label="Address line 2" name="address_line2" :optional="true"><x-ui.input name="address_line2" :value="$v('address_line2')" maxlength="200" autocomplete="off" /></x-ui.field>
            <x-ui.field label="City" name="city" :optional="true"><x-ui.input name="city" :value="$v('city')" maxlength="120" autocomplete="off" /></x-ui.field>
            <x-ui.field label="Region" name="region" :optional="true"><x-ui.input name="region" :value="$v('region')" maxlength="120" autocomplete="off" /></x-ui.field>
            <x-ui.field label="Postal code" name="postal_code" :optional="true"><x-ui.input name="postal_code" :value="$v('postal_code')" maxlength="32" autocomplete="off" /></x-ui.field>
            <x-ui.field label="Country" name="country_code" :optional="true"><x-ui.select name="country_code" :value="$v('country_code') ?? $country" placeholder="Not recorded" :options="$countries" /></x-ui.field>
        </div>
    </x-ui.card>

    <x-ui.card title="Care and administration">
        <div class="form-grid">
            <x-ui.field label="Primary clinician" name="primary_clinician_membership_id" :optional="true"><x-ui.select name="primary_clinician_membership_id" :value="$v('primary_clinician_membership_id')" placeholder="Not assigned" :options="$clinicians" /></x-ui.field>
            <x-ui.field label="Primary location" name="primary_location_id" :optional="true"><x-ui.select name="primary_location_id" :value="$v('primary_location_id')" placeholder="Not assigned" :options="$locations" /></x-ui.field>
            <x-ui.field label="Referral source" name="referral_source" :optional="true" class="form-grid__full"><x-ui.input name="referral_source" :value="$v('referral_source')" maxlength="120" autocomplete="off" /></x-ui.field>
            <x-ui.field label="Administrative notes" name="administrative_notes" :optional="true" class="form-grid__full" help="Front-desk notes only. Clinical information belongs in the clinical record."><x-ui.textarea name="administrative_notes" :value="$v('administrative_notes')" rows="4" maxlength="5000" /></x-ui.field>
        </div>
    </x-ui.card>
</div>
