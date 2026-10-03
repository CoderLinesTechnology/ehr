<x-layouts.app title="{{ $client->displayName() }} · Timeline">
    <x-slot:breadcrumbs><x-ui.breadcrumbs :items="[['label' => 'Clients', 'url' => route('app.clients.index')], ['label' => $client->displayName()]]" /></x-slot:breadcrumbs>
    @include('app.clients._header')

    <x-ui.card title="Timeline" description="What has happened on this record, newest first.">
        @if ($days->isEmpty())
            <x-ui.empty-state icon="history" title="Nothing on the timeline yet" description="Changes to the record and appointments appear here as they happen." />
        @else
            <div class="timeline">
                @foreach ($days as $day => $entries)
                    <section>
                        <h3 class="timeline__day">{{ fmt()->dayHeading($entries->first()->occurred_at) }}</h3>
                        <ul class="timeline__list">
                            @foreach ($entries as $entry)
                                @php($look = \App\Domain\Clients\ClientTimelineReader::presentation($entry->category))
                                <li class="timeline__item">
                                    <x-ui.icon-tile :icon="$look['icon']" tone="blue" shape="square" :size="36" :icon-size="18" />
                                    <div>
                                        <p class="timeline__summary">{{ $entry->summary }}</p>
                                        <p class="timeline__meta">{{ $look['label'] }}@if ($entry->actor) · {{ $entry->actor->name }}@endif</p>
                                    </div>
                                    <time class="timeline__time" datetime="{{ $entry->occurred_at->toIso8601String() }}">{{ fmt()->time($entry->occurred_at) }}</time>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>
            @if ($nextUrl)
                <x-slot:footer><x-ui.button variant="secondary" :href="$nextUrl" icon="history">Show older entries</x-ui.button></x-slot:footer>
            @elseif (! $isFirstPage)
                <x-slot:footer><x-ui.button variant="secondary" :href="route('app.clients.timeline', ['client' => $client])">Back to newest</x-ui.button></x-slot:footer>
            @endif
        @endif
    </x-ui.card>
</x-layouts.app>
