<x-layouts.app title="{{ $client->displayName() }} · Contacts">
    <x-slot:breadcrumbs><x-ui.breadcrumbs :items="[['label' => 'Clients', 'url' => route('app.clients.index')], ['label' => $client->displayName()]]" /></x-slot:breadcrumbs>
    @include('app.clients._header')

    <div class="profile-grid">
        <x-ui.card title="Contacts" :description="$contacts->count().' of '.$maxContacts.' on file'">
            @if ($contacts->isEmpty())
                <x-ui.empty-state icon="phone" title="No contacts yet" description="Add an emergency contact, a parent or guardian, or anyone else the practice may need to reach." />
            @else
                <ul class="contact-list">
                    @foreach ($contacts as $contact)
                        <li class="contact-list__item">
                            <div>
                                <p class="contact-list__name">{{ $contact->name }} @if ($contact->is_emergency_contact)<x-ui.badge tone="danger">Emergency contact</x-ui.badge>@endif</p>
                                @if (filled($contact->relationship))<p class="contact-list__line">{{ $contact->relationship }}</p>@endif
                                @if (filled($contact->phone))<p class="contact-list__line"><a href="tel:{{ $contact->phone }}">{{ \App\Support\PhoneNumbers::display($contact->phone, $country) }}</a></p>@endif
                                @if (filled($contact->email))<p class="contact-list__line"><a href="mailto:{{ $contact->email }}">{{ $contact->email }}</a></p>@endif
                                @if (filled($contact->notes))<p class="contact-list__line">{{ $contact->notes }}</p>@endif
                            </div>
                            @if ($canEdit)
                                <div class="contact-list__actions">
                                    <x-ui.button variant="secondary" size="sm" icon="pencil" :href="route('app.clients.contacts.edit', ['client' => $client, 'contact' => $contact])">Edit</x-ui.button>
                                    <x-ui.confirm-form
                                        :action="route('app.clients.contacts.destroy', ['client' => $client, 'contact' => $contact])" method="DELETE"
                                        title="Remove this contact?" :message="$contact->name.' will be removed from this client\'s record.'"
                                        confirm-label="Remove contact" button-label="Remove" button-variant="secondary" button-size="sm" button-icon="trash-2" />
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        @if ($canEdit && $contacts->count() < $maxContacts)
            <x-ui.card title="Add a contact">
                <form method="POST" action="{{ route('app.clients.contacts.store', ['client' => $client]) }}" class="form" data-submit-once novalidate>
                    @csrf
                    @include('app.clients._contact-fields', ['contact' => null])
                    <div class="form-actions"><x-ui.button type="submit" icon="plus">Add contact</x-ui.button></div>
                </form>
            </x-ui.card>
        @endif
    </div>
</x-layouts.app>
