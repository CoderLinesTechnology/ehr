{{--
    Create / edit form body, also embedded (compact) on New appointment. Needs ClientFormOptions::form() data:
    $client (Client|null), $clientType, $clinicians, $locations, $sexOptions, $contactMethods, $countries, $country,
    $billingOptions, $emailLabels, $phoneLabels, $guardianTypes, $points, $guardians, $members, $partnerOptions,
    $dobRequired, $contactRequired. Optional: $compact (bool), $prefill (first_name / last_name).

    Sections for the other client types are hidden by client-form.js and ignored by the server; without the script
    every section shows and the type radios decide which apply. Repeatable rows render one spare blank row, which
    the script removes in favour of "Add another".
--}}
@php
    $compact = $compact ?? false;
    $prefill = $prefill ?? [];
    $isNew = $client === null;
    $editingCouple = ! $isNew && $client->isCouple();
    $type = (string) old('client_type', $clientType->value);
    $v = static fn (string $key) => $client?->{$key};
    $level = $compact ? 3 : 2;
    $searchUrl = route('app.search');

    // Repeatable rows: what was posted back (minus wholly blank rows), else what is stored; then one spare blank row.
    $posted = static function (mixed $old): ?array {
        if (! is_array($old)) {
            return null;
        }
        $rows = [];
        foreach ($old as $key => $row) {
            if (is_array($row) && array_filter($row, fn ($v) => is_string($v) && trim($v) !== '' && ! in_array($v, ['parent', 'guardian', 'home', 'mobile', 'work', 'other', '0'], true)) !== []) {
                $rows[$key] = $row;
            }
        }

        return $rows;
    };
    $rowsFor = function (string $kind) use ($points, $country, $posted): array {
        if (($rows = $posted(old($kind.'s'))) !== null) {
            return $rows;
        }
        $rows = [];
        foreach ($points[$kind] ?? [] as $i => $p) {
            $rows[$i] = ['value' => $kind === 'phone' ? \App\Support\PhoneNumbers::display($p['value'], $country) : $p['value'], 'label' => $p['label']];
        }

        return $rows;
    };
    $primaryFor = function (string $kind, array $rows) use ($points): string {
        $old = old('primary_'.$kind);
        if (is_scalar($old) && (string) $old !== '') {
            return (string) $old;
        }
        foreach ($points[$kind] ?? [] as $i => $p) {
            if ($p['is_primary']) {
                return (string) $i;
            }
        }

        return (string) (array_key_first($rows) ?? '0');
    };
    $emailRows = $rowsFor('email');
    $phoneRows = $rowsFor('phone');
    $emailPrimary = $primaryFor('email', $emailRows);
    $phonePrimary = $primaryFor('phone', $phoneRows);
    $spare = static function (array $rows): string {
        $n = count($rows);
        while (array_key_exists('s'.$n, $rows)) {
            $n++;
        }

        return 's'.$n;
    };
    $guardianRows = $posted(old('guardians')) ?? [];
    $partnerRows = is_array(old('partners')) ? old('partners') : [];
    $location = $client?->is_virtual ? \App\Domain\Clients\ClientAttributes::VIRTUAL : $client?->primary_location_id;
@endphp
<div @class(['client-form', 'client-form--compact' => $compact]) data-client-form data-type="{{ $type }}" data-search-url="{{ $searchUrl }}">

    {{-- ── Type ─────────────────────────────────────────────────────────── --}}
    @if (! $editingCouple)
        <fieldset class="type-switch" @error('client_type') aria-describedby="client-type-error" @enderror>
            <legend class="type-switch__legend">Client type</legend>
            <div class="type-switch__options">
                @foreach (($isNew ? \App\Domain\Clients\ClientType::options() : \App\Domain\Clients\ClientType::individualOptions()) as $value => $label)
                    <input type="radio" class="type-switch__input" name="client_type" id="client-type-{{ $value }}" value="{{ $value }}" data-type-choice @checked($type === $value)>
                    <label class="type-switch__label" for="client-type-{{ $value }}">
                        <x-ui.icon :name="['adult' => 'user', 'minor' => 'baby', 'couple' => 'users'][$value]" :size="16" />{{ $label }}
                    </label>
                @endforeach
            </div>
            @error('client_type')<p class="field__error-item" id="client-type-error"><x-ui.icon name="alert-triangle" :size="14" /><span>{{ $message }}</span></p>@enderror
        </fieldset>
    @else
        <input type="hidden" name="client_type" value="couple">
    @endif

    {{-- ── A person (adult / minor) ─────────────────────────────────────── --}}
    @if (! $editingCouple)
        <x-ui.card title="Personal details" :level="$level" class="client-form__card" data-for-type="adult minor">
            <div class="form-grid">
                <x-ui.field label="First name" name="first_name" :required="true"><x-ui.input name="first_name" :value="$v('first_name') ?? ($prefill['first_name'] ?? null)" maxlength="100" autocomplete="off" /></x-ui.field>
                <x-ui.field label="Last name" name="last_name" :required="true"><x-ui.input name="last_name" :value="$v('last_name') ?? ($prefill['last_name'] ?? null)" maxlength="100" autocomplete="off" /></x-ui.field>
                <x-ui.field label="Date of birth" name="date_of_birth" :optional="! $dobRequired" :required="$dobRequired"><x-ui.input type="date" name="date_of_birth" :value="$client?->date_of_birth?->format('Y-m-d')" min="1900-01-01" :max="now()->format('Y-m-d')" /></x-ui.field>
                <x-ui.field label="Sex" name="sex" :optional="true"><x-ui.select name="sex" :value="$v('sex')" placeholder="Not recorded" :options="$sexOptions" /></x-ui.field>
            </div>
            <details class="client-form__more" @if (! $compact || $errors->hasAny(['middle_name', 'preferred_name', 'gender_identity', 'pronouns'])) open @endif>
                <summary class="client-form__more-toggle">Other names and identity <span class="field__opt">(optional)</span></summary>
                <div class="form-grid">
                    <x-ui.field label="Middle name" name="middle_name" :optional="true"><x-ui.input name="middle_name" :value="$v('middle_name')" maxlength="100" autocomplete="off" /></x-ui.field>
                    <x-ui.field label="Preferred name" name="preferred_name" :optional="true" help="Used in lists and greetings instead of the first name."><x-ui.input name="preferred_name" :value="$v('preferred_name')" maxlength="100" autocomplete="off" /></x-ui.field>
                    <x-ui.field label="Gender identity" name="gender_identity" :optional="true"><x-ui.input name="gender_identity" :value="$v('gender_identity')" maxlength="60" autocomplete="off" /></x-ui.field>
                    <x-ui.field label="Pronouns" name="pronouns" :optional="true"><x-ui.input name="pronouns" :value="$v('pronouns')" maxlength="40" placeholder="she/her, he/him, they/them" autocomplete="off" /></x-ui.field>
                </div>
            </details>
        </x-ui.card>
    @endif

    {{-- ── A minor's parent or guardian ─────────────────────────────────── --}}
    @if (! $editingCouple)
        <x-ui.card title="Parent or guardian" :level="$level" class="client-form__card" data-for-type="minor"
            description="A minor needs at least one parent or guardian the practice can reach (a name and a phone number or email address).">
            @error('guardians')<p class="field__error-item client-form__block-error" id="guardians-error"><x-ui.icon name="alert-triangle" :size="14" /><span>{{ $message }}</span></p>@enderror
            @if (! $isNew && $guardians->isNotEmpty())
                <ul class="guardian-list">
                    @foreach ($guardians as $guardian)
                        <li>{{ $guardian->name }} <span class="text-muted">· {{ $guardian->relationshipLabel() }}@if ($guardian->is_emergency_contact) · emergency contact @endif</span></li>
                    @endforeach
                </ul>
                <p class="text-muted text-sm">Change parents and guardians on the <a href="{{ route('app.clients.contacts.index', ['client' => $client]) }}">Contacts</a> tab.</p>
            @else
                <div class="repeat-list" data-repeat="guardian">
                    @php
                        $gRows = $guardianRows !== [] ? $guardianRows : ['0' => []];
                    @endphp
                    @foreach ($gRows + [$spare($gRows) => []] as $key => $row)
                        @include('app.clients._guardian-row', ['key' => $key, 'row' => $row, 'spareRow' => $loop->last])
                    @endforeach
                </div>
                <template data-repeat-template="guardian">@include('app.clients._guardian-row', ['key' => '__KEY__', 'row' => [], 'spareRow' => false])</template>
                <button type="button" class="repeat-add" data-repeat-add="guardian" hidden><x-ui.icon name="plus" :size="14" /><span>Add another parent or guardian</span></button>
            @endif
        </x-ui.card>
    @endif

    {{-- ── A new couple's two partners ──────────────────────────────────── --}}
    @if ($isNew)
        <x-ui.card title="Partners" :level="$level" class="client-form__card" data-for-type="couple"
            description="A couple is its own client record linked to two people. Choose each partner from your clients or add them as a new client.">
            @error('partners')<p class="field__error-item client-form__block-error"><x-ui.icon name="alert-triangle" :size="14" /><span>{{ $message }}</span></p>@enderror
            <div class="partner-grid">
                @foreach ([1, 2] as $n)
                    @php
                        $p = is_array($partnerRows[$n] ?? null) ? $partnerRows[$n] : [];
                        $mode = ($p['mode'] ?? 'existing') === 'new' ? 'new' : 'existing';
                    @endphp
                    <fieldset class="partner" data-partner>
                        <legend class="partner__title">Partner {{ $n }}</legend>
                        <div class="partner__modes" role="radiogroup" aria-label="Partner {{ $n }} is">
                            <label class="partner__mode"><input type="radio" name="partners[{{ $n }}][mode]" value="existing" data-partner-mode @checked($mode === 'existing')> Existing client</label>
                            <label class="partner__mode"><input type="radio" name="partners[{{ $n }}][mode]" value="new" data-partner-mode @checked($mode === 'new')> New client</label>
                        </div>
                        <div class="partner__existing" data-partner-for="existing">
                            <div class="partner__search" data-partner-search hidden>
                                <label class="sr-only" for="partner-{{ $n }}-search">Search your clients for partner {{ $n }}</label>
                                <input type="search" id="partner-{{ $n }}-search" class="input" placeholder="Search by name, email, phone or number" autocomplete="off" data-partner-query aria-describedby="partner-{{ $n }}-status">
                                <p class="partner__status text-xs text-muted" id="partner-{{ $n }}-status" role="status" aria-live="polite"></p>
                            </div>
                            <x-ui.field label="Client" :name="'partners['.$n.'][client_id]'">
                                <x-ui.select :name="'partners['.$n.'][client_id]'" :value="$p['client_id'] ?? null" placeholder="Choose a client" :options="$partnerOptions" data-partner-select />
                            </x-ui.field>
                        </div>
                        <div class="partner__new form-grid" data-partner-for="new">
                            <x-ui.field label="First name" :name="'partners['.$n.'][first_name]'" :required="true"><x-ui.input :name="'partners['.$n.'][first_name]'" maxlength="100" autocomplete="off" /></x-ui.field>
                            <x-ui.field label="Last name" :name="'partners['.$n.'][last_name]'" :required="true"><x-ui.input :name="'partners['.$n.'][last_name]'" maxlength="100" autocomplete="off" /></x-ui.field>
                            <x-ui.field label="Email" :name="'partners['.$n.'][email]'" :optional="! $contactRequired"><x-ui.input type="email" :name="'partners['.$n.'][email]'" maxlength="254" autocomplete="off" /></x-ui.field>
                            <x-ui.field label="Phone" :name="'partners['.$n.'][phone]'" :optional="! $contactRequired"><x-ui.input type="tel" :name="'partners['.$n.'][phone]'" maxlength="32" autocomplete="off" /></x-ui.field>
                            <x-ui.field label="Date of birth" :name="'partners['.$n.'][date_of_birth]'" :optional="! $dobRequired" :required="$dobRequired"><x-ui.input type="date" :name="'partners['.$n.'][date_of_birth]'" min="1900-01-01" :max="now()->format('Y-m-d')" /></x-ui.field>
                        </div>
                    </fieldset>
                @endforeach
            </div>
            <p class="text-muted text-sm">The couple is named after its partners (you can change the name later). New partners get the couple's clinician, location and billing.</p>
        </x-ui.card>
    @endif

    {{-- ── An existing couple: its name and members ─────────────────────── --}}
    @if ($editingCouple)
        <x-ui.card title="Couple" :level="$level" class="client-form__card">
            <div class="form-grid">
                <x-ui.field label="First names" name="first_name" :required="true" help="For example “Emily & Michael”."><x-ui.input name="first_name" :value="$client->first_name" maxlength="100" autocomplete="off" /></x-ui.field>
                <x-ui.field label="Last name" name="last_name" :required="true"><x-ui.input name="last_name" :value="$client->last_name" maxlength="100" autocomplete="off" /></x-ui.field>
                @foreach ([0, 1] as $i)
                    <x-ui.field :label="'Member '.($i + 1)" :name="'members['.$i.']'" :required="true">
                        <x-ui.select :name="'members['.$i.']'" :value="$members[$i]->id ?? null" placeholder="Choose a client" :options="$partnerOptions" />
                    </x-ui.field>
                @endforeach
            </div>
        </x-ui.card>
    @endif

    {{-- ── E-mails and phones ───────────────────────────────────────────── --}}
    <x-ui.card title="Contact" :level="$level" class="client-form__card"
        :description="$contactRequired ? 'An email address or a phone number is required.' : 'How the practice reaches this client. Mark one of each as primary.'">
        @if ($isNew)
            <p class="text-muted text-sm client-form__note" data-for-type="couple">For a couple these are the couple's shared email addresses and phone numbers (optional): each partner keeps their own.</p>
        @elseif ($editingCouple)
            <p class="text-muted text-sm client-form__note">The couple's shared email addresses and phone numbers. Each member keeps their own on their record.</p>
        @endif
        @error('email')<p class="field__error-item client-form__block-error"><x-ui.icon name="alert-triangle" :size="14" /><span>{{ $message }}</span></p>@enderror
        @foreach (['email' => [$emailRows, $emailPrimary, $emailLabels, 'Email address', 'email addresses'], 'phone' => [$phoneRows, $phonePrimary, $phoneLabels, 'Phone number', 'phone numbers']] as $kind => [$rows, $primary, $labels, $noun, $plural])
            <fieldset class="points" data-repeat-group="{{ $kind }}">
                <legend class="points__legend">{{ ucfirst($plural) }}</legend>
                @error($kind.'s')<p class="field__error-item"><x-ui.icon name="alert-triangle" :size="14" /><span>{{ $message }}</span></p>@enderror
                <div class="repeat-list" data-repeat="{{ $kind }}">
                    @php
                        $shown = $rows !== [] ? $rows : ['0' => []];
                    @endphp
                    @foreach ($shown + [$spare($shown) => []] as $key => $row)
                        @include('app.clients._point-row', ['kind' => $kind, 'key' => $key, 'row' => $row, 'primary' => $primary, 'labels' => $labels, 'noun' => $noun, 'spareRow' => $loop->last])
                    @endforeach
                </div>
                <template data-repeat-template="{{ $kind }}">@include('app.clients._point-row', ['kind' => $kind, 'key' => '__KEY__', 'row' => [], 'primary' => '', 'labels' => $labels, 'noun' => $noun, 'spareRow' => false])</template>
                <button type="button" class="repeat-add" data-repeat-add="{{ $kind }}" hidden><x-ui.icon name="plus" :size="14" /><span>Add another {{ $kind === 'email' ? 'email' : 'phone' }}</span></button>
            </fieldset>
        @endforeach
        <div class="form-grid">
            <x-ui.field label="Preferred contact method" name="preferred_contact_method" :optional="true"><x-ui.select name="preferred_contact_method" :value="$v('preferred_contact_method')" placeholder="No preference" :options="$contactMethods" /></x-ui.field>
        </div>
    </x-ui.card>

    {{-- ── Care, billing and place ──────────────────────────────────────── --}}
    <x-ui.card title="Care and billing" :level="$level" class="client-form__card">
        <div class="form-grid">
            <fieldset class="field billing-switch">
                <legend class="field__label">Billing</legend>
                <div class="billing-switch__options" data-choice-group="1">
                    @foreach ($billingOptions as $value => $label)
                        <label class="billing-switch__option"><input type="radio" name="billing_type" value="{{ $value }}" @checked((string) old('billing_type', $client?->billing_type?->value ?? 'self_pay') === $value)> {{ $label }}</label>
                    @endforeach
                </div>
                @error('billing_type')<p class="field__error-item"><x-ui.icon name="alert-triangle" :size="14" /><span>{{ $message }}</span></p>@enderror
            </fieldset>
            <x-ui.field label="Primary location" name="primary_location_id" :optional="true" help="Virtual clients book as telehealth automatically."><x-ui.select name="primary_location_id" :value="$location" placeholder="Not assigned" :options="$locations" /></x-ui.field>
            <x-ui.field label="Primary clinician" name="primary_clinician_membership_id" :optional="true" :help="$compact ? 'Left empty, the clinician chosen for the appointment (or you) is assigned.' : null"><x-ui.select name="primary_clinician_membership_id" :value="$v('primary_clinician_membership_id')" placeholder="Not assigned" :options="$clinicians" /></x-ui.field>
        </div>
        <details class="client-form__more" @if (! $compact || $errors->hasAny(['address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country_code', 'referral_source', 'administrative_notes'])) open @endif>
            <summary class="client-form__more-toggle">Address and notes <span class="field__opt">(optional)</span></summary>
            <div class="form-grid">
                <x-ui.field label="Address line 1" name="address_line1" :optional="true"><x-ui.input name="address_line1" :value="$v('address_line1')" maxlength="200" autocomplete="off" /></x-ui.field>
                <x-ui.field label="Address line 2" name="address_line2" :optional="true"><x-ui.input name="address_line2" :value="$v('address_line2')" maxlength="200" autocomplete="off" /></x-ui.field>
                <x-ui.field label="City" name="city" :optional="true"><x-ui.input name="city" :value="$v('city')" maxlength="120" autocomplete="off" /></x-ui.field>
                <x-ui.field label="Region" name="region" :optional="true"><x-ui.input name="region" :value="$v('region')" maxlength="120" autocomplete="off" /></x-ui.field>
                <x-ui.field label="Postal code" name="postal_code" :optional="true"><x-ui.input name="postal_code" :value="$v('postal_code')" maxlength="32" autocomplete="off" /></x-ui.field>
                <x-ui.field label="Country" name="country_code" :optional="true"><x-ui.select name="country_code" :value="$v('country_code') ?? $country" placeholder="Not recorded" :options="$countries" /></x-ui.field>
                <x-ui.field label="Referral source" name="referral_source" :optional="true" class="form-grid__full"><x-ui.input name="referral_source" :value="$v('referral_source')" maxlength="120" autocomplete="off" /></x-ui.field>
                <x-ui.field label="Administrative notes" name="administrative_notes" :optional="true" class="form-grid__full" help="Front-desk notes only. Clinical information belongs in the clinical record."><x-ui.textarea name="administrative_notes" :value="$v('administrative_notes')" rows="4" maxlength="5000" /></x-ui.field>
            </div>
        </details>
    </x-ui.card>
</div>
