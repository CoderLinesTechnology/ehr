@php
    $editing = $service !== null;
    $v = fn (string $field, $fallback = null) => $editing ? $service->{$field} : $fallback;
    $price = fn (string $field) => $prices[$field] ?? null;
    $selectedProviders = old('provider_ids', $providerIds);
    $selectedLocations = old('location_ids', $locationIds);
@endphp
<x-layouts.app :title="$editing ? 'Edit service' : 'Add service'">
    @include('app.settings.partials.open', ['current' => 'services', 'panelTitle' => $editing ? 'Edit service' : 'Add service', 'panelSub' => 'What you offer, how long it takes and what it costs.'])

    <form method="POST" action="{{ $editing ? route('app.settings.services.update', ['service' => $service]) : route('app.settings.services.store') }}" class="set-form set-form--wide" data-submit-once>
        @csrf
        @if ($editing) @method('PUT') @endif

        <section class="set-card">
            <div class="set-fields">
                <x-ui.field class="set-fields__full" label="Name" name="name" :required="true"><x-ui.input name="name" :value="$v('name')" maxlength="120" required /></x-ui.field>
                <x-ui.field label="Code" name="code" :optional="true"><x-ui.input name="code" :value="$v('code')" maxlength="20" /></x-ui.field>
                <x-ui.field label="Duration (minutes)" name="duration_minutes" :required="true"><x-ui.input type="number" name="duration_minutes" :value="$v('duration_minutes', 50)" min="5" max="1440" step="5" required /></x-ui.field>
                <x-ui.field class="set-fields__full" label="Description" name="description" :optional="true"><x-ui.textarea name="description" :value="$v('description')" rows="3" maxlength="2000" /></x-ui.field>
                <x-ui.field :label="'Price ('.$currency.')'" name="price" :required="true" help="The currency is fixed when the service is created."><x-ui.input name="price" :value="$price('price')" inputmode="decimal" placeholder="0.00" required /></x-ui.field>
                <x-ui.field label="Billing" name="billing_behavior" :required="true"><x-ui.select name="billing_behavior" :options="['billable' => 'Billable', 'non_billable' => 'Not billed']" :value="$v('billing_behavior', 'billable')" /></x-ui.field>
                <x-ui.field :label="'Late-cancellation fee ('.$currency.')'" name="late_cancellation_fee" :optional="true"><x-ui.input name="late_cancellation_fee" :value="$price('late_cancellation_fee')" inputmode="decimal" placeholder="0.00" /></x-ui.field>
                <x-ui.field :label="'No-show fee ('.$currency.')'" name="no_show_fee" :optional="true"><x-ui.input name="no_show_fee" :value="$price('no_show_fee')" inputmode="decimal" placeholder="0.00" /></x-ui.field>
                <x-ui.field label="Cancellation notice (hours)" name="cancellation_notice_hours" :optional="true" help="Leave empty to use the organization default."><x-ui.input type="number" name="cancellation_notice_hours" :value="$v('cancellation_notice_hours')" min="0" max="168" /></x-ui.field>
                <x-ui.field label="Calendar colour" name="color" :optional="true"><x-ui.color-input name="color" :value="$v('color')" /></x-ui.field>
            </div>
        </section>

        <section class="set-card">
            <h3 class="set-card__title">How it is offered</h3>
            <div class="stack stack--sm" style="margin-top: 12px">
                <x-ui.checkbox name="allows_in_person" label="In person" :checked="$v('allows_in_person', true)" />
                <x-ui.checkbox name="allows_telehealth" label="By telehealth" :checked="$v('allows_telehealth', false)" />
                <x-ui.checkbox name="is_bookable_online" label="Clients can book it online" :checked="$v('is_bookable_online', false)" />
                <x-ui.checkbox name="requires_documentation" label="Requires a clinical note" :checked="$v('requires_documentation', false)" />
                @error('allows_in_person')<div class="field__error"><p class="field__error-item">{{ $message }}</p></div>@enderror
            </div>
        </section>

        <section class="set-card">
            <div class="set-fields">
                <x-ui.field label="Provided by" name="provider_ids" :optional="true" help="Leave empty to let any clinician provide it.">
                    <div class="stack stack--sm" data-choice-group="1">
                        @forelse ($providers as $id => $name)
                            <x-ui.checkbox name="provider_ids[]" :id="'provider-'.$id" :value="$id" :label="$name" :checked="in_array($id, (array) $selectedProviders, true)" />
                        @empty
                            <p class="set-muted">No clinicians yet. Mark a team member as a provider first.</p>
                        @endforelse
                    </div>
                </x-ui.field>
                <x-ui.field label="Offered at" name="location_ids" :optional="true" help="Leave empty for every location.">
                    <div class="stack stack--sm" data-choice-group="1">
                        @forelse ($locations as $id => $name)
                            <x-ui.checkbox name="location_ids[]" :id="'location-'.$id" :value="$id" :label="$name" :checked="in_array($id, (array) $selectedLocations, true)" />
                        @empty
                            <p class="set-muted">No active locations.</p>
                        @endforelse
                    </div>
                </x-ui.field>
            </div>
        </section>

        <div class="form-actions">
            <x-ui.button variant="secondary" :href="route('app.settings.services.index')">Cancel</x-ui.button>
            <x-ui.button type="submit">{{ $editing ? 'Save service' : 'Add service' }}</x-ui.button>
        </div>
    </form>
    @include('app.settings.partials.close')
</x-layouts.app>
