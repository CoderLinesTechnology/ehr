@php
    $candidates = collect($staff)->reject(fn ($p) => in_array($p['id'], $thread->memberIds, true))->values();
@endphp
<x-ui.drawer id="add-people" title="Add people" description="New people see messages from the moment they join, not the earlier history.">
    <form method="post" action="{{ route('app.messages.participants.store', $thread->conversation) }}" class="form" data-submit-once>
        @csrf
        @if ($candidates->isEmpty())
            <p class="text-muted text-sm">Everyone with messaging access is already in this conversation.</p>
        @else
            <div class="msgs-people" role="group" aria-label="People to add">
                @foreach ($candidates as $person)
                    <label class="msgs-person"><input type="checkbox" name="participants[]" value="{{ $person['id'] }}"><x-ui.avatar :name="$person['name']" size="sm" decorative /><span>{{ $person['name'] }}@if ($person['title'])<small>{{ $person['title'] }}</small>@endif</span></label>
                @endforeach
            </div>
            <div class="form-actions">
                <x-ui.button variant="secondary" data-dialog-close>Cancel</x-ui.button>
                <x-ui.button type="submit">Add</x-ui.button>
            </div>
        @endif
    </form>
</x-ui.drawer>
