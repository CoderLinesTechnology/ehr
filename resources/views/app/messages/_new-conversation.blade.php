@php
    $oldKind = old('kind', $canTeam ? 'direct' : 'client');
@endphp
<x-ui.drawer id="new-conversation" title="New conversation" :open="$errors->hasAny(['kind', 'title', 'participants', 'participants.*', 'client_id'])" description="Pick a colleague, start a group, or message one of your clients.">
    <form method="post" action="{{ route('app.messages.store') }}" class="form msgs-new" data-submit-once data-new-conversation>
        @csrf
        <x-ui.field label="Type" name="kind">
            <div class="choice-list" data-choice-group>
                @if ($canTeam)
                    <label class="choice"><input type="radio" name="kind" value="direct" @checked($oldKind === 'direct') data-kind><span>Colleague</span></label>
                    <label class="choice"><input type="radio" name="kind" value="group" @checked($oldKind === 'group') data-kind><span>Group</span></label>
                @endif
                @if ($canClients)
                    <label class="choice"><input type="radio" name="kind" value="client" @checked($oldKind === 'client') data-kind><span>Client</span></label>
                @endif
            </div>
        </x-ui.field>

        <x-ui.field label="Group name" name="title" data-for-kind="group">
            <x-ui.input name="title" maxlength="120" placeholder="e.g. Therapy Team" />
        </x-ui.field>

        <x-ui.field label="People" name="participants" data-for-kind="direct group" help="Pick one colleague for a direct conversation, or several for a group.">
            @if ($staff === [])
                <p class="text-muted text-sm">No colleagues with messaging access yet.</p>
            @else
                <div class="msgs-people" role="group" aria-label="People">
                    @foreach ($staff as $person)
                        <label class="msgs-person"><input type="checkbox" name="participants[]" value="{{ $person['id'] }}" @checked(in_array($person['id'], (array) old('participants', []), true))><x-ui.avatar :name="$person['name']" size="sm" decorative /><span>{{ $person['name'] }}@if ($person['title'])<small>{{ $person['title'] }}</small>@endif</span></label>
                    @endforeach
                </div>
            @endif
        </x-ui.field>

        <x-ui.field label="Client" name="client_id" data-for-kind="client">
            @if ($clients === [])
                <p class="text-muted text-sm">No clients available to message.</p>
            @else
                <x-ui.select name="client_id" placeholder="Choose a client" :options="collect($clients)->pluck('name', 'id')->all()" />
            @endif
        </x-ui.field>

        <div class="form-actions">
            <x-ui.button variant="secondary" data-dialog-close>Cancel</x-ui.button>
            <x-ui.button type="submit">Start conversation</x-ui.button>
        </div>
    </form>
</x-ui.drawer>
