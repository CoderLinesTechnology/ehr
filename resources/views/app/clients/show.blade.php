<x-layouts.app :title="$client->displayName()">
    <x-slot:breadcrumbs><x-ui.breadcrumbs :items="[['label' => 'Clients', 'url' => route('app.clients.index')], ['label' => $client->displayName()]]" /></x-slot:breadcrumbs>
    @include('app.clients._header')

    <div class="profile-grid">
        <div class="profile-stack">
            @if ($coupleLinks !== [])
                <x-ui.card :title="$client->isCouple() ? 'Couple members' : 'Couples'" :description="$client->isCouple() ? 'The two people this couple record is for.' : 'Couple records this client belongs to.'">
                    <ul class="link-list">
                        @foreach ($coupleLinks as $line)
                            @foreach ($line['items'] as $item)
                                <li class="link-list__item">
                                    <x-ui.avatar :name="$item['text']" size="sm" :decorative="true" />
                                    <a href="{{ route('app.clients.show', ['client' => $item['client']]) }}">{{ $item['text'] }}</a>
                                    <span class="text-muted">{{ $line['label'] === 'Members' ? 'Member' : 'Couple' }}</span>
                                </li>
                            @endforeach
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif

            <x-ui.card :title="$client->isCouple() ? 'Couple details' : 'Personal details'">
                <x-ui.dl>
                    <x-ui.dl-item label="Client type">{{ $client->client_type->label() }}</x-ui.dl-item>
                    <x-ui.dl-item label="First name">{{ $client->first_name }}</x-ui.dl-item>
                    <x-ui.dl-item label="Last name">{{ $client->last_name }}</x-ui.dl-item>
                    @unless ($client->isCouple())
                    <x-ui.dl-item label="Middle name">{{ $client->middle_name }}</x-ui.dl-item>
                    <x-ui.dl-item label="Preferred name">{{ $client->preferred_name }}</x-ui.dl-item>
                    <x-ui.dl-item label="Date of birth">@if ($client->date_of_birth){{ fmt()->date($client->date_of_birth) }} ({{ $client->age() }})@endif</x-ui.dl-item>
                    <x-ui.dl-item label="Sex">{{ $client->sex ? \App\Domain\Clients\ClientSex::tryFrom($client->sex)?->label() : '' }}</x-ui.dl-item>
                    <x-ui.dl-item label="Gender identity">{{ $client->gender_identity }}</x-ui.dl-item>
                    <x-ui.dl-item label="Pronouns">{{ $client->pronouns }}</x-ui.dl-item>
                    @endunless
                </x-ui.dl>
            </x-ui.card>

            <x-ui.card title="Contact">
                <x-ui.dl>
                    <x-ui.dl-item label="Phone numbers">
                        @forelse ($points['phone'] as $p)
                            <span class="point-line"><a href="tel:{{ $p['value'] }}">{{ \App\Support\PhoneNumbers::display($p['value'], $country) }}</a> <span class="text-muted">{{ \App\Domain\Clients\ContactPointLabel::from($p['label'])->label() }}</span>@if ($p['is_primary'] && count($points['phone']) > 1) <x-ui.badge tone="info">Primary</x-ui.badge>@endif</span>
                        @empty
                        @endforelse
                    </x-ui.dl-item>
                    <x-ui.dl-item label="Email addresses">
                        @forelse ($points['email'] as $p)
                            <span class="point-line"><a href="mailto:{{ $p['value'] }}">{{ $p['value'] }}</a> <span class="text-muted">{{ \App\Domain\Clients\ContactPointLabel::from($p['label'])->label() }}</span>@if ($p['is_primary'] && count($points['email']) > 1) <x-ui.badge tone="info">Primary</x-ui.badge>@endif</span>
                        @empty
                        @endforelse
                    </x-ui.dl-item>
                    <x-ui.dl-item label="Preferred contact method">{{ $client->preferred_contact_method ? \App\Domain\Clients\ContactMethod::tryFrom($client->preferred_contact_method)?->label() : '' }}</x-ui.dl-item>
                    <x-ui.dl-item label="Address" :wide="true">{{ collect([$client->address_line1, $client->address_line2, $client->city, $client->region, $client->postal_code, $client->country_code ? (\App\Support\Regions::countries()[$client->country_code] ?? $client->country_code) : null])->filter()->implode(', ') }}</x-ui.dl-item>
                </x-ui.dl>
            </x-ui.card>

            <x-ui.card title="Care">
                <x-ui.dl>
                    <x-ui.dl-item label="Primary clinician">{{ $client->primaryClinician?->displayName() }}</x-ui.dl-item>
                    <x-ui.dl-item label="Primary location">{{ $client->is_virtual ? 'Virtual (telehealth)' : $client->primaryLocation?->name }}</x-ui.dl-item>
                    <x-ui.dl-item label="Billing">{{ $client->billing_type->label() }}</x-ui.dl-item>
                    <x-ui.dl-item label="Referral source">{{ $client->referral_source }}</x-ui.dl-item>
                    <x-ui.dl-item label="Registered">{{ fmt()->localDate($client->created_at) }}</x-ui.dl-item>
                </x-ui.dl>
            </x-ui.card>

            @if (filled($client->administrative_notes))
                <x-ui.card title="Administrative notes" description="Front-desk notes. Not part of the clinical record.">
                    <p class="profile-note">{{ $client->administrative_notes }}</p>
                </x-ui.card>
            @endif
        </div>

        <div class="profile-stack">
            <x-ui.card title="Status">
                <div class="profile-status">
                    <x-ui.badge :tone="['active' => 'success', 'pending' => 'pending', 'inactive' => 'neutral', 'archived' => 'neutral'][$client->status->value]">{{ $client->status->label() }}</x-ui.badge>
                    @if ($client->archived_at)<span class="profile-status__since">since {{ fmt()->localDate($client->archived_at) }}</span>@endif
                </div>
                @if ($statusActions !== [])
                    <div class="profile-actions">
                        @foreach ($statusActions as $action)
                            <x-ui.confirm-form
                                :action="route('app.clients.status', ['client' => $client])"
                                :title="$action['title']"
                                :message="$action['message']"
                                :confirm-label="$action['confirm']"
                                :tone="$action['danger'] ? 'danger' : 'primary'"
                                :button-label="$action['label']"
                                button-variant="secondary"
                                button-size="sm"
                                :button-icon="$action['icon']"
                                :reason-field="$action['reason'] ? 'reason' : null"
                                reason-label="Why is the status changing?"
                                :reason-required="true"
                                :bag="'status_'.$action['to']->value">
                                <input type="hidden" name="status" value="{{ $action['to']->value }}">
                            </x-ui.confirm-form>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>

            @if ($showAppointments)
                <x-ui.card title="Next appointment">
                    @if ($appointments['next'])
                        <div class="profile-next">
                            <x-ui.icon-tile icon="calendar" tone="blue" shape="square" :size="40" :icon-size="20" />
                            <div class="profile-next__text">
                                <strong>{{ fmt()->localDate($appointments['next']['at'], $appointments['next']['timezone']) }}</strong>
                                <span>{{ fmt()->time($appointments['next']['at'], $appointments['next']['timezone']) }}</span>
                            </div>
                        </div>
                    @else
                        <p class="text-muted">Nothing scheduled.</p>
                    @endif
                </x-ui.card>
            @endif

            <x-ui.card title="Contacts" :description="$client->contacts->count().' on file'">
                <x-slot:actions><x-ui.button variant="tonal" size="sm" :href="route('app.clients.contacts.index', ['client' => $client])">View</x-ui.button></x-slot:actions>
                @forelse ($client->contacts->take(3) as $contact)
                    <div class="contact-list__item">
                        <div>
                            <p class="contact-list__name">{{ $contact->name }} @if ($contact->isGuardian())<x-ui.badge tone="info">Guardian</x-ui.badge>@endif @if ($contact->is_emergency_contact)<x-ui.badge tone="danger">Emergency</x-ui.badge>@endif</p>
                            <p class="contact-list__line">{{ collect([$contact->relationshipLabel(), filled($contact->phone) ? \App\Support\PhoneNumbers::display($contact->phone, $country) : null])->filter()->implode(' · ') }}</p>
                        </div>
                    </div>
                @empty
                    <p class="text-muted">{{ $client->isMinor() ? 'No parent or guardian on file yet.' : 'No emergency or other contacts yet.' }}</p>
                @endforelse
            </x-ui.card>
        </div>
    </div>
</x-layouts.app>
