@php
    $tabs = ['all' => ['All', $inbox->unread['all']], 'clients' => ['Clients', $inbox->unread['clients']], 'team' => ['Team', null], 'groups' => ['Groups', null]];
@endphp
<nav class="msgs-tabs" aria-label="Conversation type">
    @foreach ($tabs as $key => [$label, $count])
        <a href="{{ route('app.messages.index', array_filter(['tab' => $key === 'all' ? null : $key, 'q' => $inbox->q ?: null, 'unread' => $inbox->unreadOnly ? 1 : null])) }}"
           class="msgs-tab @if ($inbox->tab === $key) is-active @endif" data-tab="{{ $key }}" @if ($inbox->tab === $key) aria-current="page" @endif>
            <span>{{ $label }}</span>@if ($count)<span class="msgs-tab__count" data-count="{{ $key }}">{{ $count }}<span class="sr-only"> unread</span></span>@endif
        </a>
    @endforeach
</nav>
