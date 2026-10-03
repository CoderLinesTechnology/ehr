@php
    $shortTime = function (?\Carbon\CarbonImmutable $at): string {
        if ($at === null) { return ''; }
        $local = fmt()->local($at);
        $today = fmt()->local(now());
        return match (true) {
            $local->isSameDay($today) => fmt()->time($at),
            $local->isSameDay($today->subDay()) => 'Yesterday',
            $local->year === $today->year => $local->format('M j'),
            default => fmt()->localDate($at),
        };
    };
@endphp
@if ($inbox->rows === [])
    @if ($inbox->q !== '' || $inbox->unreadOnly)
        <x-ui.empty-state icon="search" title="No conversations match" description="Try different words, or clear the search to see everything.">
            <x-slot:actions><x-ui.button variant="secondary" size="sm" :href="route('app.messages.index')">Clear search</x-ui.button></x-slot:actions>
        </x-ui.empty-state>
    @else
        <x-ui.empty-state icon="message-circle" title="No conversations yet" description="Start one with a colleague or a client.">
            @if ($canTeam || $canClients)
                <x-slot:actions><x-ui.button size="sm" icon="plus" data-dialog-open="new-conversation">New conversation</x-ui.button></x-slot:actions>
            @endif
        </x-ui.empty-state>
    @endif
@else
    <ul class="msg-rows" role="list">
        @foreach ($inbox->rows as $row)
            <li>
                <a href="{{ route('app.messages.show', $row->id) }}" class="msg-row @if ($row->id === $selectedId) is-selected @endif" data-conversation="{{ $row->id }}" @if ($row->id === $selectedId) aria-current="page" @endif>
                    <span class="msg-row__avatar">
                        @if ($row->kind->value === 'group')
                            <span class="msg-avatar-group" aria-hidden="true"><x-ui.icon name="users" :size="20" /></span>
                        @else
                            <x-ui.avatar :name="$row->name" size="md" decorative />
                        @endif
                        @if ($row->online)<span class="msg-dot" title="Online"><span class="sr-only">Online</span></span>@endif
                    </span>
                    <span class="msg-row__body">
                        <span class="msg-row__name">{{ $row->name }}</span>
                        <span class="msg-row__preview">{{ $row->preview }}</span>
                    </span>
                    <span class="msg-row__side">
                        <time class="msg-row__time" @if ($row->at) datetime="{{ $row->at->toIso8601String() }}" @endif>{{ $shortTime($row->at) }}</time>
                        @if ($row->unread > 0)<span class="msg-badge" data-unread="{{ $row->id }}">{{ $row->unread > 99 ? '99+' : $row->unread }}<span class="sr-only"> unread</span></span>@endif
                    </span>
                </a>
            </li>
        @endforeach
    </ul>
    @if ($inbox->next !== null)
        <a class="msgs-more" href="{{ route('app.messages.index', array_filter(['tab' => $inbox->tab === 'all' ? null : $inbox->tab, 'q' => $inbox->q ?: null, 'unread' => $inbox->unreadOnly ? 1 : null, 'after' => $inbox->next])) }}">Show older conversations</a>
    @endif
@endif
