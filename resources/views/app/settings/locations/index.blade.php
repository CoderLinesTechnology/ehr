<x-layouts.app title="Locations">
    @push('settings-actions')<x-ui.button icon="plus" :href="route('app.settings.locations.create')">Add location</x-ui.button>@endpush
    @include('app.settings.partials.open', ['current' => 'locations'])

    @if ($locations->isEmpty())
        <x-ui.empty-state icon="map-pin" title="No locations yet" description="Add the place where you see clients. Appointments are booked at a location.">
            <x-slot:actions><x-ui.button icon="plus" :href="route('app.settings.locations.create')">Add location</x-ui.button></x-slot:actions>
        </x-ui.empty-state>
    @else
        <section class="set-card set-card--flush" aria-label="Locations">
            <ul class="set-list">
                @foreach ($locations as $location)
                    <li>
                        <div class="set-list__main">
                            <p class="set-list__title">{{ $location->name }}
                                <x-ui.badge :tone="$location->is_active ? 'success' : 'neutral'">{{ $location->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
                            </p>
                            <p class="set-list__meta">{{ $location->displayAddress() ?: 'No address yet' }} · {{ $location->timezone }}</p>
                            <p class="set-list__meta">{{ \App\Domain\Organization\BusinessHours::summary($location->business_hours) }}</p>
                        </div>
                        <div class="set-list__actions">
                            <x-ui.button variant="secondary" size="sm" icon="pencil" :href="route('app.settings.locations.edit', ['location' => $location])">Edit</x-ui.button>
                            @if ($location->is_active)
                                <x-ui.confirm-form :action="route('app.settings.locations.status', ['location' => $location])" method="PATCH" :title="'Deactivate '.$location->name.'?'"
                                    message="It will no longer be offered when booking. Existing appointments and records are kept." confirm-label="Deactivate" button-label="Deactivate" button-size="sm" button-variant="neutral">
                                    <input type="hidden" name="active" value="0">
                                </x-ui.confirm-form>
                            @else
                                <form method="POST" action="{{ route('app.settings.locations.status', ['location' => $location]) }}" data-submit-once>
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="active" value="1">
                                    <x-ui.button type="submit" variant="neutral" size="sm">Reactivate</x-ui.button>
                                </form>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
    @include('app.settings.partials.close')
</x-layouts.app>
