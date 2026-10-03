@php
    $selected = $thread !== null;
@endphp
<x-layouts.app title="Messages">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/screens/messages.css') }}?v={{ filemtime(public_path('css/screens/messages.css')) }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/screens/messages.js') }}?v={{ filemtime(public_path('js/screens/messages.js')) }}" defer></script>
    @endpush

    <div class="msgs" data-msgs data-view="{{ $selected ? 'thread' : 'list' }}"
         data-list-url="{{ route('app.messages.index') }}" data-signature="{{ $signature }}"
         @if ($selected) data-poll-url="{{ route('app.messages.poll', $thread->conversation) }}" data-read-url="{{ route('app.messages.read', $thread->conversation) }}" @endif>

        {{-- Conversation list ------------------------------------------------ --}}
        <section class="msgs-list card" aria-labelledby="msgs-title">
            <header class="msgs-list__head">
                <div>
                    <h1 id="msgs-title" class="msgs-list__title">Messages</h1>
                    <p class="msgs-list__sub">Stay connected with your clients and team.</p>
                </div>
                @if ($canTeam || $canClients)
                    <button type="button" class="msgs-icon-btn" data-dialog-open="new-conversation" aria-label="New conversation" title="New conversation"><x-ui.icon name="square-pen" :size="18" /></button>
                @endif
            </header>

            <div data-part="tabs">@include('app.messages._tabs')</div>

            <form class="msgs-search" method="get" action="{{ route('app.messages.index') }}" role="search" data-msgs-search>
                <input type="hidden" name="tab" value="{{ $inbox->tab }}">
                @if ($inbox->unreadOnly)<input type="hidden" name="unread" value="1">@endif
                <label class="msgs-search__field">
                    <x-ui.icon name="search" :size="16" />
                    <span class="sr-only">Search conversations</span>
                    <input type="search" name="q" value="{{ $inbox->q }}" placeholder="Search conversations..." autocomplete="off" maxlength="100" enterkeyhint="search">
                </label>
                <a class="msgs-search__filter @if ($inbox->unreadOnly) is-on @endif" href="{{ route('app.messages.index', array_filter(['tab' => $inbox->tab === 'all' ? null : $inbox->tab, 'q' => $inbox->q ?: null, 'unread' => $inbox->unreadOnly ? null : 1])) }}"
                   aria-label="{{ $inbox->unreadOnly ? 'Show all conversations' : 'Show only conversations with unread messages' }}" aria-pressed="{{ $inbox->unreadOnly ? 'true' : 'false' }}" title="Unread only">
                    <x-ui.icon name="funnel" :size="17" />
                </a>
            </form>

            <div class="msgs-list__rows" data-part="rows">@include('app.messages._rows')</div>
        </section>

        {{-- Thread ------------------------------------------------------------ --}}
        <section class="msgs-thread card" aria-label="Conversation" data-kind="{{ $selected ? $thread->conversation->kind->value : '' }}">
            @if ($selected)
                @include('app.messages._thread')
            @else
                <div class="msgs-thread__none">
                    <x-ui.empty-state icon="message-circle" title="Select a conversation" description="Choose a conversation from the list to read and reply, or start a new one." />
                </div>
            @endif
        </section>
    </div>

    @if ($canTeam || $canClients)
        @include('app.messages._new-conversation')
    @endif
    @if ($selected && $thread->conversation->kind->value !== 'direct' && ($canTeam))
        @include('app.messages._add-people')
    @endif
</x-layouts.app>
