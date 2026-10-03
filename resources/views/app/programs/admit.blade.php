<x-layouts.app title="Add participant">
    @push('styles')
        @include('app.programs._head')
    @endpush

    <div class="pr pr--page">
        <x-ui.page-header title="Add participant" description="Admit a client to a program." icon="users-round" />

        @if ($program === null)
            <section class="pr-panel" aria-labelledby="admit-pick">
                <h2 id="admit-pick" class="pr-panel__title">Choose a program</h2>
                @if ($programs->isEmpty())
                    <p class="pr-empty">There is no upcoming or active program you can admit clients to.</p>
                @else
                    <ul class="pr-picklist">
                        @foreach ($programs as $p)
                            <li><a href="{{ route('app.programs.admit', ['program' => $p]) }}"><span class="pr-tile pr-tile--{{ $p->color->value }} pr-tile--sm" aria-hidden="true"><x-ui.icon :name="$p->icon" :size="20" /></span><span>{{ $p->name }}</span><x-ui.icon name="chevron-right" :size="16" /></a></li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @else
            <section class="pr-panel" aria-labelledby="admit-client">
                <h2 id="admit-client" class="pr-panel__title">{{ $program->name }}</h2>
                <form method="GET" action="{{ route('app.programs.admit') }}" role="search" class="pr-inline-search">
                    <input type="hidden" name="program" value="{{ $program->id }}">
                    <label for="client-search" class="sr-only">Search clients</label>
                    <x-ui.input id="client-search" name="q" :value="$q" maxlength="100" placeholder="Search by client name" />
                    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>
                </form>

                <form method="POST" action="{{ route('app.programs.admit.store') }}" class="pr-form" data-submit-once novalidate>
                    @csrf
                    <input type="hidden" name="program_id" value="{{ $program->id }}">
                    <x-ui.field label="Client" name="client_id" :required="true">
                        @if ($clients->isEmpty())
                            <p class="pr-empty">No clients match. Only active or pending clients that you may see, and that are not already in this program, can be admitted.</p>
                        @else
                            <ul class="pr-radiolist">
                                @foreach ($clients as $client)
                                    <li><label><input type="radio" name="client_id" value="{{ $client->id }}" @checked(old('client_id') === $client->id) required><span>{{ $client->displayName() }}</span><small>{{ $client->formattedNumber() }}</small></label></li>
                                @endforeach
                            </ul>
                        @endif
                    </x-ui.field>
                    @if ($levels->isNotEmpty())
                        <x-ui.field label="Level of care" name="level_id" :required="true"><x-ui.select name="level_id" :options="$levels->pluck('name', 'id')->all()" placeholder="Choose a level" required /></x-ui.field>
                    @endif
                    <div class="form-actions">
                        <x-ui.button variant="secondary" :href="route('app.programs.show', ['program' => $program, 'tab' => 'participants'])">Cancel</x-ui.button>
                        <x-ui.button type="submit" :disabled="$clients->isEmpty()">Admit to program</x-ui.button>
                    </div>
                </form>
            </section>
        @endif
    </div>
</x-layouts.app>
