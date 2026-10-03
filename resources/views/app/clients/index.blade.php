@php
    $statusTone = ['active' => 'success', 'pending' => 'pending', 'inactive' => 'neutral', 'archived' => 'neutral'];
    $status = $filters->status === 'open' ? '' : $filters->status;
@endphp
<x-layouts.app title="Clients">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/screens/clients.css') }}?v={{ filemtime(public_path('css/screens/clients.css')) }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/screens/clients.js') }}?v={{ filemtime(public_path('js/screens/clients.js')) }}" defer></script>
    @endpush

    <x-ui.page-header class="clients-page" title="Clients" description="Manage your clients and view their information." icon="users">
        <x-slot:actions>
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route('app.clients.create')">Add Client</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="stat-row stat-row--rail clients-stats">
        <x-ui.stat label="Total Clients" :value="number_format($stats['total']['value'])" icon="users" tone="blue" :trend="$stats['total']['trend']" />
        <x-ui.stat label="Active Clients" :value="number_format($stats['active']['value'])" icon="user-check" tone="teal" :trend="$stats['active']['trend']" />
        <x-ui.stat label="Upcoming Appointments" :value="$stats['upcoming']['value'] === null ? '—' : number_format($stats['upcoming']['value'])" icon="clock" tone="blue" :trend="$stats['upcoming']['trend']" />
        <x-ui.stat label="New Clients (30 days)" :value="number_format($stats['new']['value'])" icon="user-plus" tone="blue" :trend="$stats['new']['trend']" />
    </div>

    <div class="page-columns clients-columns" data-clients-columns>
        <x-ui.card :padded="false" class="clients-card">
            <div class="clients-toolbar">
                <div class="clients-search">
                    <x-ui.icon name="search" :size="15" class="clients-search__icon" />
                    <label for="client-search" class="sr-only">Search clients</label>
                    <input type="search" id="client-search" name="q" form="client-filters" value="{{ $filters->q }}" class="clients-search__input" placeholder="Search clients by name, email, phone, or ID..." autocomplete="off" enterkeyhint="search" maxlength="100">
                </div>
                <a href="#client-filters" class="clients-filters-btn" data-filters-toggle aria-controls="client-filters" aria-expanded="true">
                    <x-ui.icon name="funnel" :size="14" /><span>Filters</span>
                </a>
            </div>

            @if ($canBulk)
                <div class="clients-bulk" data-bulk-bar hidden>
                    <span class="clients-bulk__count" data-bulk-count role="status" aria-live="polite"></span>
                    <button type="button" class="clients-bulk__btn" data-dialog-open="bulk-inactive"><x-ui.icon name="user-minus" :size="14" /><span>Mark inactive</span></button>
                    <button type="button" class="clients-bulk__clear" data-bulk-clear>Clear selection</button>
                </div>
            @endif

            @if ($clients->isEmpty())
                @if ($filters->isNarrowed())
                    <x-ui.empty-state icon="search" title="No clients match" description="Try different words, or reset the search and filters to see everyone.">
                        <x-slot:actions>
                            <x-ui.button variant="secondary" :href="route('app.clients.index')">Reset search and filters</x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @elseif ($canCreate)
                    <x-ui.empty-state icon="users" title="No clients yet" description="Add your first client to start scheduling appointments and keeping their record.">
                        <x-slot:actions>
                            <x-ui.button icon="plus" :href="route('app.clients.create')">Add Client</x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state icon="users" title="No clients to show"
                        :description="$seesAll ? 'There are no clients in this organization yet.' : 'Clients appear here when you are their primary clinician or have had an appointment with them.'" />
                @endif
            @else
                <x-ui.table label="Clients" class="clients-table-wrap">
                    <caption class="sr-only">Clients with contact details, status and appointments</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="col-check">
                                @if ($canBulk)
                                    <input type="checkbox" class="client-check" data-select-all aria-label="Select all clients on this page" hidden>
                                @endif
                            </th>
                            <th scope="col" class="col-client">Client</th>
                            <th scope="col" class="col-contact">Contact</th>
                            <th scope="col" class="col-status">Status</th>
                            @if ($showAppointments)
                                <th scope="col" class="col-next">Next Appointment</th>
                                <th scope="col" class="col-last">Last Visit</th>
                            @endif
                            <th scope="col" class="col-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($clients as $client)
                            @php($row = $appointments[$client->id] ?? ['next' => null, 'last' => null])
                            <tr>
                                <td class="col-check">
                                    @if ($canBulk)
                                        <input type="checkbox" class="client-check" name="clients[]" value="{{ $client->id }}" form="bulk-inactive-form" data-row-check aria-label="Select {{ $client->displayName() }}" hidden>
                                    @endif
                                </td>
                                <td class="col-client">
                                    <div class="client-cell">
                                        <x-ui.avatar :name="$client->displayName()" class="client-cell__avatar" :decorative="true" />
                                        <div class="client-cell__text">
                                            <a href="{{ route('app.clients.show', ['client' => $client]) }}" class="client-cell__name">{{ $client->displayName() }}</a>
                                            <span class="client-cell__id">{{ $client->formattedNumber() }}@if ($client->isDemo()) <x-ui.badge tone="demo" class="client-cell__demo">Demo</x-ui.badge>@endif</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="col-contact">
                                    <div class="contact-cell">
                                        <span class="contact-cell__line">
                                            <x-ui.icon name="phone" :size="11" />
                                            @if (filled($client->phone))<span>{{ \App\Support\PhoneNumbers::display($client->phone, null) }}</span>@else<span class="muted-dash" aria-hidden="true">—</span><span class="sr-only">No phone number</span>@endif
                                        </span>
                                        <span class="contact-cell__line contact-cell__line--mail">
                                            <x-ui.icon name="mail" :size="12" />
                                            @if (filled($client->email))<span class="contact-cell__email">{{ $client->email }}</span>@else<span class="muted-dash" aria-hidden="true">—</span><span class="sr-only">No email address</span>@endif
                                        </span>
                                    </div>
                                </td>
                                <td class="col-status"><x-ui.badge :tone="$statusTone[$client->status->value]" class="status-pill">{{ $client->status->label() }}</x-ui.badge></td>
                                @if ($showAppointments)
                                    <td class="col-next">
                                        @if ($row['next'])
                                            <span class="next-cell">
                                                <x-ui.icon name="calendar" :size="14" />
                                                <span>
                                                    <span class="next-cell__date">{{ fmt()->localDate($row['next']['at'], $row['next']['timezone']) }}</span>
                                                    <span class="next-cell__time">{{ fmt()->time($row['next']['at'], $row['next']['timezone']) }}</span>
                                                </span>
                                            </span>
                                        @else
                                            <span class="muted-dash" aria-hidden="true">—</span><span class="sr-only">None scheduled</span>
                                        @endif
                                    </td>
                                    <td class="col-last">
                                        @if ($row['last'])
                                            {{ fmt()->localDate($row['last']) }}
                                        @else
                                            <span class="muted-dash" aria-hidden="true">—</span><span class="sr-only">No visits yet</span>
                                        @endif
                                    </td>
                                @endif
                                <td class="col-actions">
                                    <x-ui.dropdown icon="ellipsis" :label="'Actions for '.$client->displayName()" align="right" class="row-actions">
                                        <x-ui.dropdown-item icon="user" :href="route('app.clients.show', ['client' => $client])">View profile</x-ui.dropdown-item>
                                        @if ($canEdit)
                                            <x-ui.dropdown-item icon="pencil" :href="route('app.clients.edit', ['client' => $client])">Edit client</x-ui.dropdown-item>
                                        @endif
                                        @if ($canBook && $client->status->value !== 'archived')
                                            <x-ui.dropdown-item icon="calendar-plus" :href="route('app.appointments.create', ['client' => $client])">Book appointment</x-ui.dropdown-item>
                                        @endif
                                    </x-ui.dropdown>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif

            @include('app.clients._pager', ['clients' => $clients])
        </x-ui.card>

        <x-ui.filter-panel :action="route('app.clients.index')" :reset-url="route('app.clients.index')" id="client-filters" class="clients-filters">
            <input type="hidden" name="sort" value="{{ $filters->sort }}">
            @if ($filters->direction === 'desc')<input type="hidden" name="direction" value="desc">@endif
            <x-ui.field label="Status" name="status">
                <x-ui.select name="status" :value="$status" placeholder="All Statuses" :options="['active' => 'Active', 'pending' => 'Pending', 'inactive' => 'Inactive', 'archived' => 'Archived']" />
            </x-ui.field>
            @if (count($locations) > 0)
                <x-ui.field label="Location" name="location">
                    <x-ui.select name="location" :value="$filters->location" placeholder="All Locations" :options="$locations" />
                </x-ui.field>
            @endif
            <x-ui.field label="Clinician" name="clinician">
                <x-ui.select name="clinician" :value="$filters->clinician" placeholder="All Clinicians" :options="['none' => 'No clinician assigned'] + $clinicians" />
            </x-ui.field>
            {{-- Program: shown when the Programs module exists. --}}
            <x-ui.field label="Client Type" name="records">
                <x-ui.select name="records" :value="$filters->records === 'all' ? '' : $filters->records" placeholder="All Client Types" :options="['live' => 'Live records', 'demo' => 'Demo records']" />
            </x-ui.field>
            <div class="field">
                <span class="field__label" id="visit-range-label">Last Appointment</span>
                <div class="range-inputs" role="group" aria-labelledby="visit-range-label">
                    <label class="range-input">
                        <x-ui.icon name="calendar" :size="13" />
                        <span class="sr-only">From date</span>
                        <input type="text" name="from" value="{{ old('from', $filters->visitedFrom) }}" placeholder="Start date" inputmode="numeric" autocomplete="off" data-date-input>
                    </label>
                    <label class="range-input">
                        <x-ui.icon name="arrow-left-right" :size="13" />
                        <span class="sr-only">To date</span>
                        <input type="text" name="to" value="{{ old('to', $filters->visitedTo) }}" placeholder="End date" inputmode="numeric" autocomplete="off" data-date-input>
                    </label>
                </div>
            </div>
            <div class="filter-panel__row">
                <x-ui.toggle name="mine" value="1" :checked="$filters->mine" :hidden-default="false" label="Only show my clients" />
            </div>
        </x-ui.filter-panel>
    </div>

    @if ($canBulk)
        <x-ui.modal id="bulk-inactive" title="Mark the selected clients inactive?" description="Inactive clients stay in the list and keep their record. Archived clients and clients you may not edit are left as they are." size="sm" :open="$errors->has('clients') || $errors->has('reason') || $errors->has('clients.*')">
            <form method="POST" action="{{ route('app.clients.bulk-status') }}" id="bulk-inactive-form" class="stack" data-submit-once>
                @csrf
                @error('clients')<p class="field__error-item"><x-ui.icon name="alert-triangle" :size="14" /><span>{{ $message }}</span></p>@enderror
                <x-ui.field label="Reason" name="reason" :required="true">
                    <x-ui.textarea name="reason" id="bulk-reason" rows="3" :required="true" />
                </x-ui.field>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" data-dialog-close>Cancel</x-ui.button>
                <x-ui.button type="submit" form="bulk-inactive-form">Mark inactive</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</x-layouts.app>
