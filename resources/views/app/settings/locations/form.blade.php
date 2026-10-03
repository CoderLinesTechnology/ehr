@php
    $editing = $location !== null;
    $value = fn (string $field, $fallback = null) => $editing ? $location->{$field} : ($defaults[$field] ?? $fallback);
@endphp
<x-layouts.app :title="$editing ? 'Edit location' : 'Add location'">
    @include('app.settings.partials.open', ['current' => 'locations', 'panelTitle' => $editing ? 'Edit location' : 'Add location', 'panelSub' => 'Where you see clients, and when you are open.'])

    <form method="POST" action="{{ $editing ? route('app.settings.locations.update', ['location' => $location]) : route('app.settings.locations.store') }}" class="set-form set-form--wide" data-submit-once>
        @csrf
        @if ($editing) @method('PUT') @endif

        <section class="set-card">
            <div class="set-fields">
                <x-ui.field class="set-fields__full" label="Name" name="name" :required="true"><x-ui.input name="name" :value="$value('name')" maxlength="120" required /></x-ui.field>
                <x-ui.field label="Address" name="address_line1" :optional="true"><x-ui.input name="address_line1" :value="$value('address_line1')" /></x-ui.field>
                <x-ui.field label="Address line 2" name="address_line2" :optional="true"><x-ui.input name="address_line2" :value="$value('address_line2')" /></x-ui.field>
                <x-ui.field label="City" name="city" :optional="true"><x-ui.input name="city" :value="$value('city')" /></x-ui.field>
                <x-ui.field label="Region" name="region" :optional="true"><x-ui.input name="region" :value="$value('region')" /></x-ui.field>
                <x-ui.field label="Postal code" name="postal_code" :optional="true"><x-ui.input name="postal_code" :value="$value('postal_code')" /></x-ui.field>
                <x-ui.field label="Country" name="country_code" :optional="true"><x-ui.select name="country_code" :options="$countries" placeholder="Choose a country" :value="$value('country_code')" /></x-ui.field>
                <x-ui.field label="Phone" name="phone" :optional="true"><x-ui.input type="tel" name="phone" :value="$value('phone')" /></x-ui.field>
                <x-ui.field label="Email" name="email" :optional="true"><x-ui.input type="email" name="email" :value="$value('email')" /></x-ui.field>
                <x-ui.field class="set-fields__full" label="Timezone" name="timezone" :required="true" help="Appointments at this location are shown in this timezone."><x-ui.select name="timezone" :options="$timezones" :value="$value('timezone')" /></x-ui.field>
            </div>
        </section>

        <section class="set-card">
            <h3 class="set-card__title">Opening hours</h3>
            <p class="set-card__sub">Leave a day closed if you do not see clients that day.</p>
            <div class="set-hours" style="margin-top: 14px">
                @foreach ($days as $day => $dayName)
                    @php $row = old("hours.$day", $hours[$day]); $closed = filter_var($row['closed'] ?? false, FILTER_VALIDATE_BOOL); @endphp
                    <div class="set-hours__row">
                        <span class="set-row__label">{{ $dayName }}</span>
                        <x-ui.checkbox :name="'hours['.$day.'][closed]'" :id="'hours-'.$day.'-closed'" label="Closed" :checked="$closed" />
                        <div class="set-hours__times">
                            <label class="sr-only" for="hours-{{ $day }}-open">{{ $dayName }} opens</label>
                            <x-ui.input type="time" :name="'hours['.$day.'][open]'" :id="'hours-'.$day.'-open'" :value="$row['open'] ?? '08:00'" />
                            <span aria-hidden="true">to</span>
                            <label class="sr-only" for="hours-{{ $day }}-close">{{ $dayName }} closes</label>
                            <x-ui.input type="time" :name="'hours['.$day.'][close]'" :id="'hours-'.$day.'-close'" :value="$row['close'] ?? '17:00'" />
                        </div>
                        @error("hours.$day.open")<div class="field__error set-fields__full"><p class="field__error-item">{{ $message }}</p></div>@enderror
                        @error("hours.$day.close")<div class="field__error set-fields__full"><p class="field__error-item">{{ $message }}</p></div>@enderror
                    </div>
                @endforeach
            </div>
        </section>

        <div class="form-actions">
            <x-ui.button variant="secondary" :href="route('app.settings.locations.index')">Cancel</x-ui.button>
            <x-ui.button type="submit">{{ $editing ? 'Save location' : 'Add location' }}</x-ui.button>
        </div>
    </form>
    @include('app.settings.partials.close')
</x-layouts.app>
