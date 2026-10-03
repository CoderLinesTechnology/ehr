<x-layouts.app title="Search">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/screens/clients.css') }}?v={{ filemtime(public_path('css/screens/clients.css')) }}">
    @endpush
    <x-ui.page-header title="Search" :description="$results->term !== '' ? 'Results for “'.$results->term.'”' : 'Search clients and staff by name, email, phone number or client number.'" icon="search" />

    <form method="GET" action="{{ route('app.search') }}" role="search" class="search-form">
        <label for="search-q" class="sr-only">Search</label>
        <div class="input-wrap input-wrap--icon">
            <x-ui.icon name="search" :size="18" class="input-wrap__icon" />
            <input type="search" id="search-q" name="q" value="{{ $results->term }}" class="input" placeholder="Search clients and staff…" maxlength="100" autocomplete="off" enterkeyhint="search" autofocus>
        </div>
        <x-ui.button type="submit">Search</x-ui.button>
    </form>

    @if ($results->term === '')
        <x-ui.card><x-ui.empty-state icon="search" title="Start typing to search" description="Try a name, an email address, a phone number or a client number like CL-0012." /></x-ui.card>
    @elseif (! $results->canSearchClients && ! $results->canSearchStaff)
        <x-ui.card><x-ui.empty-state icon="lock" title="Nothing to search" description="Your role does not include searching clients or staff." /></x-ui.card>
    @else
        <div class="search-results">
            @if ($results->canSearchClients)
                <x-ui.card :title="'Clients'" :padded="false">
                    @forelse ($results->clients->items as $client)
                        <a href="{{ route('app.clients.show', ['client' => $client]) }}" class="result-row">
                            <x-ui.avatar :name="$client->displayName()" class="result-row__avatar" :decorative="true" />
                            <span class="result-row__text">
                                <span class="result-row__name">{{ $client->displayName() }} @if ($client->isDemo())<x-ui.badge tone="demo">Demo</x-ui.badge>@endif</span>
                                <span class="result-row__sub">{{ $client->formattedNumber() }}@if (filled($client->phone)) · {{ \App\Support\PhoneNumbers::display($client->phone, $country) }}@endif</span>
                            </span>
                            <x-ui.badge :tone="['active' => 'success', 'pending' => 'pending', 'inactive' => 'neutral', 'archived' => 'neutral'][$client->status->value]">{{ $client->status->label() }}</x-ui.badge>
                        </a>
                    @empty
                        <div class="card__body"><x-ui.empty-state icon="users" title="No clients found" description="Check the spelling, or try part of the name, email or phone number." /></div>
                    @endforelse
                    @if ($results->clients->more)
                        <div class="card__footer"><a href="{{ route('app.clients.index', ['q' => $results->term, 'status' => 'all']) }}">Show every matching client in the client list</a></div>
                    @endif
                </x-ui.card>
            @endif

            @if ($results->canSearchStaff)
                <x-ui.card title="Staff" :padded="false">
                    @forelse ($results->staff->items as $member)
                        <div class="result-row">
                            <x-ui.avatar :name="$member->displayName()" class="result-row__avatar" :decorative="true" />
                            <span class="result-row__text">
                                <span class="result-row__name">{{ $member->displayName() }}</span>
                                <span class="result-row__sub">{{ $member->title ?: $member->user->email }}</span>
                            </span>
                        </div>
                    @empty
                        <div class="card__body"><x-ui.empty-state icon="user" title="No staff found" description="Try part of a name or email address." /></div>
                    @endforelse
                    @if ($results->staff->more)<div class="card__footer text-muted">More staff match: add more of the name to narrow them down.</div>@endif
                </x-ui.card>
            @endif
        </div>
    @endif
</x-layouts.app>
