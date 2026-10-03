@php
    $conversation = $thread->conversation;
    $kind = $conversation->kind->value;
    $isGroup = $kind === 'group';
@endphp
<header class="msgs-thread__head">
    <a class="msgs-back" href="{{ route('app.messages.index') }}" aria-label="Back to conversations"><x-ui.icon name="chevron-left" :size="22" /></a>
    @if ($isGroup)
        <span class="msg-avatar-group msg-avatar-group--head" aria-hidden="true"><x-ui.icon name="users" :size="24" /></span>
    @else
        <x-ui.avatar :name="$thread->title" size="lg" decorative class="msgs-thread__avatar" />
    @endif
    <div class="msgs-thread__who">
        <h2 class="msgs-thread__name">{{ $thread->title }}</h2>
        @if ($kind === 'direct')
            <p class="msgs-thread__status @if ($thread->online) is-online @endif">@if ($thread->online)<span class="msg-status-dot" aria-hidden="true"></span>Online @else Offline @endif</p>
        @elseif ($isGroup)
            <p class="msgs-thread__status" title="{{ implode(', ', $thread->members) }}">{{ $thread->memberCount }} {{ \Illuminate\Support\Str::plural('member', $thread->memberCount) }}</p>
        @else
            <p class="msgs-thread__status msgs-thread__status--note">The client will see this conversation when the client portal is enabled.</p>
        @endif
    </div>
    <div class="msgs-thread__tools">
        <button type="button" class="msgs-tool" aria-disabled="true" title="Calls arrive with Telehealth" aria-label="Call (available with Telehealth)"><x-ui.icon name="phone" :size="20" /></button>
        <button type="button" class="msgs-tool" aria-disabled="true" title="Video arrives with Telehealth" aria-label="Video call (available with Telehealth)"><x-ui.icon name="video" :size="20" /></button>
        @if ($isGroup)
            <x-ui.dropdown label="Conversation options" align="right">
                <x-slot:trigger><span class="msgs-tool" aria-label="Conversation options"><x-ui.icon name="ellipsis-vertical" :size="20" /></span></x-slot:trigger>
                <button type="button" class="dropdown__item" data-dialog-open="add-people"><x-ui.icon name="user-plus" :size="16" /><span>Add people</span></button>
                <form method="post" action="{{ route('app.messages.leave', $conversation) }}" data-submit-once>
                    @csrf
                    <button type="submit" class="dropdown__item dropdown__item--danger"><x-ui.icon name="log-out" :size="16" /><span>Leave group</span></button>
                </form>
            </x-ui.dropdown>
        @else
            <button type="button" class="msgs-tool" aria-disabled="true" aria-label="More options" title="No options for this conversation"><x-ui.icon name="ellipsis-vertical" :size="20" /></button>
        @endif
    </div>
</header>

<div class="msgs-scroll" data-scroll tabindex="-1">
    @if ($thread->earlier !== null)
        <div class="msgs-earlier"><a href="{{ route('app.messages.show', ['conversation' => $conversation, 'before' => $thread->earlier]) }}" data-earlier="{{ $thread->earlier }}">Load earlier messages</a></div>
    @endif
    <div class="msgs-flow" data-flow data-latest="{{ $thread->latest }}" data-read-until="{{ $thread->readUntil }}">
        @include('app.messages._messages', ['messages' => $thread->messages, 'afterDay' => null])
    </div>
    @if ($thread->messages === [])
        <p class="msgs-empty" data-empty>No messages yet. Say hello below.</p>
    @endif
</div>

<form class="composer" method="post" action="{{ route('app.messages.send', $conversation) }}" enctype="multipart/form-data" data-composer>
    @csrf
    @error('body')<p class="composer__error" role="alert">{{ $message }}</p>@enderror
    @error('attachment')<p class="composer__error" role="alert">{{ $message }}</p>@enderror
    @if (session('error') && ! $errors->has('body') && ! $errors->has('attachment'))<p class="composer__error" role="alert">{{ session('error') }}</p>@endif
    <p class="composer__error" role="alert" data-composer-error hidden></p>
    <div class="composer__row">
        <label class="composer__attach" title="Attach a PDF, PNG or JPEG (up to 10 MB)">
            <x-ui.icon name="paperclip" :size="20" /><span class="sr-only">Attach a file</span>
            <input type="file" name="attachment" accept="application/pdf,image/png,image/jpeg" data-attachment>
        </label>
        <div class="composer__field">
            <label class="sr-only" for="composer-body">Type a message</label>
            <textarea id="composer-body" name="body" rows="1" maxlength="5000" placeholder="Type a message..." data-body autocomplete="off">{{ old('body') }}</textarea>
            <span class="composer__file" data-file hidden></span>
            <details class="composer__emoji">
                <summary aria-label="Insert an emoji"><x-ui.icon name="smile" :size="20" /></summary>
                <div class="composer__emoji-menu" role="group" aria-label="Emoji">
                    @foreach (['😊', '👍', '❤️', '🙏', '🎉', '👏', '😂', '😢', '👋', '✅'] as $emoji)
                        <button type="button" data-emoji="{{ $emoji }}">{{ $emoji }}</button>
                    @endforeach
                </div>
            </details>
        </div>
        <button type="submit" class="composer__send" aria-label="Send message"><x-ui.icon name="send" :size="18" :stroke="2" /></button>
    </div>
</form>
