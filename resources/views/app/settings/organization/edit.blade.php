@php
    $initials = \Illuminate\Support\Str::of($organization->name)->explode(' ')->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
    $statusValue = $organization->status instanceof \BackedEnum ? $organization->status->value : (string) $organization->status;
    $isActive = $statusValue === 'active';
    $site = $organization->website ? preg_replace('#^https?://#i', '', rtrim($organization->website, '/')) : null;
    $address = collect([$organization->address_line1, $organization->address_line2, $organization->city, $organization->region, $organization->postal_code, $countries[$organization->country_code] ?? null])->filter()->implode(', ');
    $descriptionValue = old('description', $organization->description);
    $oldSection = old('section');
    $hasErrors = $errors->any();
@endphp
<x-layouts.app title="Organization settings">
    @include('app.settings.partials.open', ['current' => 'organization'])

    <div class="set-stack">
        {{-- Organization Profile --}}
        <section class="set-card" aria-labelledby="card-profile">
            <div class="set-card__head set-card__head--raised">
                <span class="set-card__icon" style="--tile: 35px; --gap: 11px" aria-hidden="true"><x-ui.icon name="building-2" :size="20" /></span>
                <div class="set-card__text"><h3 class="set-card__title" id="card-profile">Organization Profile</h3></div>
                <button type="button" class="set-btn" data-dialog-open="org-profile-drawer"><x-ui.icon name="pencil" :size="14" /><span>Edit</span></button>
            </div>
            <div class="set-profile">
                <div class="set-profile__id">
                    <span class="set-logo">@if ($logoUrl)<img src="{{ $logoUrl }}" alt="Logo of {{ $organization->name }}" width="96" height="96">@else<span aria-hidden="true">{{ $initials }}</span>@endif</span>
                    <div>
                        <p class="set-profile__name">{{ $organization->name }}</p>
                        @if (filled($organization->tagline))<p class="set-profile__tagline">{{ $organization->tagline }}</p>@endif
                        <span class="set-pill @unless ($isActive) set-pill--muted @endunless">{{ $isActive ? 'Active' : ucfirst(str_replace('_', ' ', $statusValue)) }}</span>
                    </div>
                </div>
                <div class="set-profile__rule" aria-hidden="true"></div>
                <ul class="set-contacts">
                    @if ($organization->email)<li><x-ui.icon name="mail" :size="14" /><a href="mailto:{{ $organization->email }}">{{ $organization->email }}</a></li>@endif
                    @if ($organization->phone)<li><x-ui.icon name="phone" :size="14" /><span>{{ $organization->phone }}</span></li>@endif
                    @if ($address !== '')<li><x-ui.icon name="map-pin" :size="14" /><span>{{ $address }}</span></li>@endif
                    @if ($site)<li><x-ui.icon name="globe" :size="14" /><a href="{{ $organization->website }}" rel="noopener noreferrer" target="_blank">{{ $site }}</a></li>@endif
                    @unless ($organization->email || $organization->phone || $address !== '' || $site)<li class="set-contacts__none">No contact details yet.</li>@endunless
                </ul>
            </div>
        </section>

        <form method="POST" action="{{ route('app.settings.organization.update') }}" id="org-general-form" data-submit-once novalidate>
            @csrf
            @method('PUT')
            <input type="hidden" name="section" value="general">

            {{-- General Information --}}
            <section class="set-card" aria-labelledby="card-general">
                <div class="set-card__head set-card__head--short">
                    <span class="set-card__icon set-card__icon--plain" style="--tile: 20px; --gap: 14px" aria-hidden="true"><x-ui.icon name="info" :size="20" /></span>
                    <div class="set-card__text"><h3 class="set-card__title set-card__title--sm" id="card-general">General Information</h3></div>
                </div>
                <div class="set-two">
                    <div class="set-rows">
                        <div class="set-row set-row--text"><span class="set-row__label">Organization Name</span><span class="set-row__value">{{ $organization->name }}</span></div>
                        <div class="set-row">
                            <label class="set-row__label" for="timezone">Timezone</label>
                            <x-ui.field name="timezone"><x-ui.select name="timezone" :options="$timezones" :value="$organization->timezone" /></x-ui.field>
                        </div>
                        <div class="set-row">
                            <label class="set-row__label" for="currency">Currency</label>
                            <x-ui.field name="currency"><x-ui.select name="currency" :options="$currencies" :value="$organization->currency" /></x-ui.field>
                        </div>
                        <div class="set-row">
                            <label class="set-row__label" for="date-format">Date Format</label>
                            <x-ui.field name="settings[general__date_format]" id="date-format"><x-ui.select name="settings[general__date_format]" id="date-format" :options="$dateFormats" :value="$values['date_format']" /></x-ui.field>
                        </div>
                        <div class="set-row">
                            <label class="set-row__label" for="locale">Language</label>
                            <x-ui.field name="locale"><x-ui.select name="locale" :options="$locales" :value="$organization->locale" /></x-ui.field>
                        </div>
                    </div>
                    <div class="set-two__rule" aria-hidden="true"></div>
                    <div>
                        <label class="set-desc__label" for="description">Description</label>
                        <x-ui.field name="description">
                            <x-ui.textarea name="description" :value="$organization->description" maxlength="500" data-count-target="description-count" />
                        </x-ui.field>
                        <p class="set-counter" aria-live="off"><span id="description-count">{{ mb_strlen((string) $descriptionValue) }}/500</span></p>
                    </div>
                </div>
            </section>

            {{-- Branding --}}
            <section class="set-card set-card--brand" aria-labelledby="card-branding">
                <div class="set-card__head" style="min-height: 28px">
                    <span class="set-card__icon" style="--tile: 28px; --gap: 12px; --tile-bg: #e6f1fd" aria-hidden="true"><x-ui.icon name="image" :size="18" /></span>
                    <div class="set-card__text"><h3 class="set-card__title set-card__title--sm" id="card-branding">Branding</h3></div>
                </div>
                <div class="set-brand">
                    <div>
                        <p class="set-brand__label" id="logo-label">Logo</p>
                        <div class="set-brand__logo">
                            <span class="set-brand__tile">@if ($logoUrl)<img src="{{ $logoUrl }}" alt="" width="66" height="66">@else<span aria-hidden="true">{{ $initials }}</span>@endif</span>
                            <div class="set-brand__side">
                                <input type="file" id="logo-file" name="logo" form="org-logo-form" accept="image/png,image/jpeg" class="sr-only" data-logo-input aria-labelledby="logo-label">
                                <label for="logo-file" class="set-btn">Change</label>
                                <noscript><button type="submit" form="org-logo-form" class="set-btn">Upload</button></noscript>
                                <p class="set-help">Recommended size: 512 x 512px<br>File type: PNG, JPG</p>
                                @error('logo')<div class="field__error"><p class="field__error-item"><x-ui.icon name="alert-triangle" :size="14" /><span>{{ $message }}</span></p></div>@enderror
                            </div>
                        </div>
                    </div>
                    <div class="set-brand__rule" aria-hidden="true"></div>
                    <div class="set-colors">
                        <div class="set-color">
                            <label class="set-color__label" for="primary-color">Primary Color</label>
                            <x-ui.field name="settings[branding__primary_color]" id="primary-color"><x-ui.color-input name="settings[branding__primary_color]" id="primary-color" :value="$values['primary_color']" /></x-ui.field>
                        </div>
                        <div class="set-color">
                            <label class="set-color__label" for="secondary-color">Secondary Color</label>
                            <x-ui.field name="settings[branding__secondary_color]" id="secondary-color"><x-ui.color-input name="settings[branding__secondary_color]" id="secondary-color" :value="$values['secondary_color']" /></x-ui.field>
                        </div>
                    </div>
                </div>
            </section>
        </form>

        {{-- Contact Information --}}
        <section class="set-card" aria-labelledby="card-contact">
            <div class="set-card__head set-card__head--sub">
                <span class="set-card__icon" style="--tile: 32px; --gap: 11px; --tile-bg: #edf2f9" aria-hidden="true"><x-ui.icon name="mail" :size="20" /></span>
                <div class="set-card__text">
                    <h3 class="set-card__title set-card__title--sm" id="card-contact">Contact Information</h3>
                    <p class="set-card__sub">Keep your contact details up to date.</p>
                </div>
                <button type="button" class="set-btn" data-dialog-open="org-contact-drawer"><x-ui.icon name="pencil" :size="14" /><span>Edit</span></button>
            </div>
            <dl class="set-dl">
                <div><dt>Email</dt><dd>{{ $organization->email ?: '—' }}</dd></div>
                <div><dt>Phone</dt><dd>{{ $organization->phone ?: '—' }}</dd></div>
                <div><dt>Website</dt><dd>{{ $organization->website ?: '—' }}</dd></div>
                <div><dt>Legal name</dt><dd>{{ $organization->legal_name ?: '—' }}</dd></div>
                <div class="set-dl__wide"><dt>Address</dt><dd>{{ $address !== '' ? $address : '—' }}</dd></div>
            </dl>
        </section>

        {{-- Regional formats (saved with the general form above) --}}
        <section class="set-card" aria-labelledby="card-formats">
            <div class="set-card__head">
                <span class="set-card__icon" style="--tile: 32px; --gap: 11px; --tile-bg: #edf2f9" aria-hidden="true"><x-ui.icon name="clock" :size="18" /></span>
                <div class="set-card__text">
                    <h3 class="set-card__title set-card__title--sm" id="card-formats">Regional Formats</h3>
                    <p class="set-card__sub">How times and weeks are shown to your team.</p>
                </div>
            </div>
            <div class="set-formats">
                <x-ui.field label="Time format" name="settings[general__time_format]" id="time-format">
                    <x-ui.select name="settings[general__time_format]" id="time-format" form="org-general-form" :options="$timeFormats" :value="$values['time_format']" />
                </x-ui.field>
                <x-ui.field label="Week starts on" name="settings[general__week_starts_on]" id="week-starts-on">
                    <x-ui.select name="settings[general__week_starts_on]" id="week-starts-on" form="org-general-form" :options="$weekStarts" :value="$values['week_starts_on']" />
                </x-ui.field>
            </div>
        </section>

        <div class="set-savebar" data-dirty-form="org-general-form">
            <p class="set-savebar__note">Changes to the details above are saved together.</p>
            <x-ui.button type="submit" form="org-general-form">Save changes</x-ui.button>
        </div>
    </div>

    <form method="POST" action="{{ route('app.settings.organization.logo.store') }}" id="org-logo-form" enctype="multipart/form-data" data-submit-once>@csrf</form>

    @include('app.settings.partials.close')

    {{-- Edit drawers --}}
    <x-ui.drawer id="org-profile-drawer" title="Organization profile" description="The name and tagline shown on your profile." :open="$hasErrors && $oldSection === 'profile'">
        <form method="POST" action="{{ route('app.settings.organization.update') }}" id="org-profile-form" class="form" data-submit-once>
            @csrf
            @method('PUT')
            <input type="hidden" name="section" value="profile">
            <x-ui.field label="Organization name" name="name" :required="true"><x-ui.input name="name" :value="$organization->name" maxlength="160" required /></x-ui.field>
            <x-ui.field label="Legal name" name="legal_name" :optional="true"><x-ui.input name="legal_name" :value="$organization->legal_name" maxlength="200" /></x-ui.field>
            <x-ui.field label="Tagline" name="tagline" :optional="true"><x-ui.input name="tagline" :value="$organization->tagline" maxlength="120" /></x-ui.field>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" data-dialog-close>Cancel</x-ui.button>
            <x-ui.button type="submit" form="org-profile-form">Save</x-ui.button>
        </x-slot:footer>
    </x-ui.drawer>

    <x-ui.drawer id="org-contact-drawer" title="Contact information" description="How clients and colleagues can reach you." :open="$hasErrors && $oldSection === 'contact'">
        <form method="POST" action="{{ route('app.settings.organization.update') }}" id="org-contact-form" class="form" data-submit-once>
            @csrf
            @method('PUT')
            <input type="hidden" name="section" value="contact">
            <x-ui.field label="Email" name="email" :optional="true"><x-ui.input type="email" name="email" :value="$organization->email" autocomplete="off" /></x-ui.field>
            <x-ui.field label="Phone" name="phone" :optional="true"><x-ui.input type="tel" name="phone" :value="$organization->phone" autocomplete="off" /></x-ui.field>
            <x-ui.field label="Website" name="website" :optional="true" help="Start with https://"><x-ui.input type="url" name="website" :value="$organization->website" autocomplete="off" /></x-ui.field>
            <x-ui.field label="Address" name="address_line1" :optional="true"><x-ui.input name="address_line1" :value="$organization->address_line1" /></x-ui.field>
            <x-ui.field label="Address line 2" name="address_line2" :optional="true"><x-ui.input name="address_line2" :value="$organization->address_line2" /></x-ui.field>
            <div class="form-grid">
                <x-ui.field label="City" name="city" :optional="true"><x-ui.input name="city" :value="$organization->city" /></x-ui.field>
                <x-ui.field label="Region" name="region" :optional="true"><x-ui.input name="region" :value="$organization->region" /></x-ui.field>
                <x-ui.field label="Postal code" name="postal_code" :optional="true"><x-ui.input name="postal_code" :value="$organization->postal_code" /></x-ui.field>
                <x-ui.field label="Country" name="country_code" :required="true"><x-ui.select name="country_code" :options="$countries" :value="$organization->country_code" /></x-ui.field>
            </div>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" data-dialog-close>Cancel</x-ui.button>
            <x-ui.button type="submit" form="org-contact-form">Save</x-ui.button>
        </x-slot:footer>
    </x-ui.drawer>
</x-layouts.app>
