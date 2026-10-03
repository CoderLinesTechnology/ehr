@php($tone = ['scheduled' => 'neutral', 'confirmed' => 'success', 'checked_in' => 'info', 'in_progress' => 'info', 'completed' => 'completed', 'cancelled' => 'danger', 'no_show' => 'warning', 'rescheduled' => 'neutral'])
<x-layouts.app title="{{ $client->displayName() }} · Appointments">
    <x-slot:breadcrumbs><x-ui.breadcrumbs :items="[['label' => 'Clients', 'url' => route('app.clients.index')], ['label' => $client->displayName()]]" /></x-slot:breadcrumbs>
    @include('app.clients._header')

    @if ($ownOnly)
        <x-ui.alert tone="info" class="mb-4">You see the appointments you are the clinician on.</x-ui.alert>
    @endif

    <div class="stack">
        <x-ui.card title="Upcoming">
            @if ($upcoming->isEmpty())
                <x-ui.empty-state icon="calendar" title="Nothing scheduled" description="Upcoming appointments for this client appear here." />
            @else
                <ul class="appt-list">
                    @foreach ($upcoming as $appointment)
                        @include('app.clients._appointment-row', ['appointment' => $appointment, 'tone' => $tone])
                    @endforeach
                </ul>
                @if ($upcomingHasMore)<p class="text-muted text-sm">More appointments are scheduled than shown here.</p>@endif
            @endif
        </x-ui.card>

        <x-ui.card title="Past appointments" :padded="false">
            @if ($past->isEmpty())
                <div class="card__body"><x-ui.empty-state icon="history" title="No past appointments" description="Completed and past appointments appear here." /></div>
            @else
                <div class="card__body"><ul class="appt-list">
                    @foreach ($past as $appointment)
                        @include('app.clients._appointment-row', ['appointment' => $appointment, 'tone' => $tone])
                    @endforeach
                </ul></div>
                {{ $past->links() }}
            @endif
        </x-ui.card>
    </div>
</x-layouts.app>
