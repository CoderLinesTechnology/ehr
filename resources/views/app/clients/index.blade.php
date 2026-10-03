<x-layouts.app title="Clients">
    <x-ui.page-header title="Clients" description="Everyone in your care, searchable by name, email, phone number or client number.">
        <x-slot:actions>
            @if ($canCreate)
                <x-ui.button icon="user-plus" :href="route('app.clients.create')">Add client</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="stack">
        <x-ui.filter-bar :action="route('app.clients.index')" label="Client filters">
            <x-ui.input type="search" name="q" :value="$filters->q" icon="search" placeholder="Search name, email, phone or number" aria-label="Search clients" autocomplete="off" enterkeyhint="search" />
            <x-ui.select name="status" :value="$filters->status" data-autosubmit aria-label="Status" :options="[
                'open' => 'Status: active and inactive',
                'active' => 'Status: active',
                'inactive' => 'Status: inactive',
                'archived' => 'Status: archived',
                'all' => 'Status: all',
            ]" />
            <x-ui.select name="clinician" :value="$filters->clinician" data-autosubmit aria-label="Primary clinician" placeholder="Any clinician" :options="['none' => 'No clinician assigned'] + $clinicians" />
            <x-ui.select name="records" :value="$filters->records" data-autosubmit aria-label="Records" :options="[
                'all' => 'Records: live and demo',
                'live' => 'Records: live',
                'demo' => 'Records: demo',
            ]" />
            @if ($filters->sort !== 'last_name' || $filters->direction !== 'asc')
                <input type="hidden" name="sort" value="{{ $filters->sort }}">
                <input type="hidden" name="direction" value="{{ $filters->direction }}">
            @endif
            <x-ui.button type="submit" variant="secondary">Search</x-ui.button>
        </x-ui.filter-bar>

        @if ($clients->isEmpty())
            <x-ui.card>
                @if ($filters->isNarrowed())
                    <x-ui.empty-state icon="search" title="No clients match" description="Try different words, or clear the search and filters to see everyone.">
                        <x-slot:actions>
                            <x-ui.button variant="secondary" :href="route('app.clients.index')">Clear search and filters</x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @elseif ($canCreate)
                    <x-ui.empty-state icon="users" title="No clients yet" description="Add your first client to start scheduling appointments and keeping their record.">
                        <x-slot:actions>
                            <x-ui.button icon="user-plus" :href="route('app.clients.create')">Add client</x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state icon="users" title="No clients to show"
                        :description="$seesAll ? 'There are no clients in this organization yet.' : 'Clients appear here when you are their primary clinician or have had an appointment with them.'" />
                @endif
            </x-ui.card>
        @else
            <x-ui.card :padded="false">
                <x-ui.table label="Clients">
                    <caption class="sr-only">Clients, sortable by name, client number and date added</caption>
                    <thead>
                        <tr>
                            <x-ui.sort-link :th="true" column="last_name" label="Name" />
                            <x-ui.sort-link :th="true" column="client_number" label="Client no." />
                            <th scope="col">Date of birth</th>
                            <th scope="col">Phone</th>
                            <th scope="col">Primary clinician</th>
                            <th scope="col">Status</th>
                            <x-ui.sort-link :th="true" column="created" label="Added" />
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($clients as $client)
                            <tr>
                                <td>
                                    <div class="cluster">
                                        <x-ui.avatar :name="$client->displayName()" size="sm" :decorative="true" />
                                        <a href="{{ route('app.clients.show', ['client' => $client]) }}" class="fw-semibold">{{ $client->displayName() }}</a>
                                        @if ($client->isDemo())<x-ui.badge tone="demo">Demo</x-ui.badge>@endif
                                    </div>
                                </td>
                                <td class="tabular nowrap">{{ $client->formattedNumber() }}</td>
                                <td class="nowrap">
                                    @if ($client->date_of_birth)
                                        {{ fmt()->date($client->date_of_birth) }} <span class="text-muted">({{ $client->age() }})</span>
                                    @else
                                        <span class="text-subtle" aria-hidden="true">&mdash;</span><span class="sr-only">Not provided</span>
                                    @endif
                                </td>
                                <td class="nowrap">
                                    @if (filled($client->phone))
                                        @if (preg_match('/^\+\d{8,15}$/', $client->phone))
                                            <a href="tel:{{ $client->phone }}">{{ \App\Support\PhoneNumbers::display($client->phone, $country) }}</a>
                                        @else
                                            {{ $client->phone }}
                                        @endif
                                    @else
                                        <span class="text-subtle" aria-hidden="true">&mdash;</span><span class="sr-only">Not provided</span>
                                    @endif
                                </td>
                                <td>{{ $client->primaryClinician?->displayName() ?? '—' }}</td>
                                <td><x-ui.badge :tone="$client->status->tone()">{{ $client->status->label() }}</x-ui.badge></td>
                                <td class="nowrap">{{ fmt()->localDate($client->created_at) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </x-ui.card>

            {{ $clients->links() }}
        @endif
    </div>
</x-layouts.app>
