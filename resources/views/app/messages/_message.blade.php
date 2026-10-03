@php
    $mayReact = ! $m->retracted;
@endphp
<article class="msg {{ $m->mine ? 'msg--out' : 'msg--in' }} @if ($m->retracted) msg--removed @endif" data-msg="{{ $m->id }}" data-cursor="{{ $m->cursor }}" data-at="{{ $m->at->utc()->format('Y-m-d\TH:i:s.u\Z') }}" @if ($m->mine) data-mine @endif>
    @unless ($m->mine)<x-ui.avatar :name="$m->sender" size="md" decorative class="msg__avatar" />@endunless
    <div class="msg__col">
        <div class="msg__bubble">
            @if ($m->retracted)
                <p class="msg__text msg__text--removed">This message was removed</p>
            @else
                @unless ($m->mine)<span class="msg__sender">{{ $m->sender }}</span>@endunless
                @if ($m->body !== '')<p class="msg__text">{{ $m->body }}</p>@endif
                @foreach ($m->attachments as $file)
                    @if ($m->isImage($file))
                        <a class="msg__image" href="{{ route('app.messages.attachments.show', $file['id']) }}" target="_blank" rel="noopener"><img src="{{ route('app.messages.attachments.show', $file['id']) }}" alt="Image attachment: {{ $file['name'] }}" loading="lazy"></a>
                    @else
                        <a class="msg__file" href="{{ route('app.messages.attachments.show', $file['id']) }}"><x-ui.icon name="file-text" :size="18" /><span>{{ $file['name'] }}</span><small>{{ number_format($file['size'] / 1024 / 1024, 1) }} MB</small></a>
                    @endif
                @endforeach
            @endif
            <p class="msg__meta">
                <time datetime="{{ $m->at->toIso8601String() }}">{{ fmt()->time($m->at) }}</time>
                @if ($m->mine && ! $m->retracted)
                    <span class="msg__ticks @if ($m->read) is-read @endif" data-ticks title="{{ $m->read ? 'Read' : 'Sent' }}">
                        <x-ui.icon name="check" :size="14" :stroke="2" class="tick-sent" /><x-ui.icon name="check-check" :size="14" :stroke="2" class="tick-read" />
                        <span class="sr-only" data-ticks-label>{{ $m->read ? 'Read' : 'Sent' }}</span>
                    </span>
                @endif
            </p>
        </div>
        @if ($mayReact)
            <div class="msg__extras">
                @foreach ($m->reactions as $r)
                    <form method="post" action="{{ route('app.messages.react', [request()->route('conversation'), $m->id]) }}" data-react>
                        @csrf
                        <input type="hidden" name="emoji" value="{{ $r['emoji'] }}">
                        <button type="submit" class="msg-chip @if ($r['mine']) is-mine @endif" aria-pressed="{{ $r['mine'] ? 'true' : 'false' }}" aria-label="{{ $r['emoji'] }} {{ $r['count'] }}"><span aria-hidden="true">{{ $r['emoji'] }}</span> <span>{{ $r['count'] }}</span></button>
                    </form>
                @endforeach
                <div class="msg__actions">
                    <details class="msg__react">
                        <summary aria-label="Add a reaction" title="Add a reaction"><x-ui.icon name="smile-plus" :size="16" /></summary>
                        <form method="post" action="{{ route('app.messages.react', [request()->route('conversation'), $m->id]) }}" class="msg__react-menu" data-react>
                            @csrf
                            @foreach (\App\Domain\Messaging\Reactions::ALLOWED as $emoji)
                                <button type="submit" name="emoji" value="{{ $emoji }}" aria-label="React with {{ $emoji }}">{{ $emoji }}</button>
                            @endforeach
                        </form>
                    </details>
                    @if ($m->canRetract)
                        <form method="post" action="{{ route('app.messages.retract', [request()->route('conversation'), $m->id]) }}" data-retract>
                            @csrf
                            <button type="submit" class="msg__act" title="Remove this message (possible for 5 minutes)"><x-ui.icon name="trash-2" :size="14" /><span>Remove</span></button>
                        </form>
                    @endif
                </div>
            </div>
        @endif
    </div>
</article>
