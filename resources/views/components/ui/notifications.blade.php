@props([
    'items' => [],
    'unread' => 0,
    'viewAllUrl' => null,
])
@php
    // Bell + dropdown. The red dot shows only when $unread > 0: never fake data. Each item:
    // ['title', 'subtitle', 'time', 'icon', 'tone', 'url', 'unread' => bool, 'mention' => bool].
    $unread = max(0, (int) $unread);
    $items = array_values((array) $items);
    $safe = static fn ($url) => filled($url) && ! preg_match('/^\s*(?:javascript|data|vbscript):/i', (string) $url) ? (string) $url : null;
    $hasMentions = collect($items)->contains(fn ($i) => ! empty($i['mention']));
    $label = $unread > 0 ? 'Notifications, '.$unread.' unread' : 'Notifications';
@endphp
<details {{ $attributes->class(['dropdown', 'dropdown--right', 'notifications']) }} data-notifications>
    <summary class="dropdown__trigger" aria-label="{{ $label }}">
        <x-ui.icon name="bell" :size="25" :stroke="2" />
        @if ($unread > 0)<span class="notifications__dot" aria-hidden="true"></span>@endif
    </summary>
    <div class="dropdown__menu">
        <div class="notifications__head"><h2 class="notifications__title">Notifications</h2></div>
        @if ($items === [])
            <div class="dropdown__empty">
                <x-ui.icon name="bell" :size="24" />
                <p class="dropdown__empty-title">You are all caught up</p>
                <p>There are no new notifications.</p>
            </div>
        @else
            <div class="notifications__tabs" role="tablist" aria-label="Filter notifications">
                <button type="button" class="notifications__tab" role="tab" aria-selected="true" data-notif-tab="all" data-keep-open>All</button>
                <button type="button" class="notifications__tab" role="tab" aria-selected="false" data-notif-tab="unread" data-keep-open>Unread</button>
                @if ($hasMentions)<button type="button" class="notifications__tab" role="tab" aria-selected="false" data-notif-tab="mention" data-keep-open>Mentions</button>@endif
            </div>
            <ul class="notifications__list" role="list">
                @foreach ($items as $item)
                    @php($url = $safe($item['url'] ?? null))
                    <li data-notif-unread="{{ ! empty($item['unread']) ? '1' : '0' }}" data-notif-mention="{{ ! empty($item['mention']) ? '1' : '0' }}">
                        <{{ $url ? 'a' : 'div' }} @if ($url) href="{{ $url }}" @endif @class(['notification', 'is-unread' => ! empty($item['unread'])])>
                            <x-ui.icon-tile class="notification__icon" :icon="$item['icon'] ?? 'bell'" :tone="$item['tone'] ?? 'default'" :size="32" :icon-size="16" />
                            <span class="notification__text">
                                <span class="notification__title">{{ $item['title'] ?? '' }}</span>
                                @if (filled($item['subtitle'] ?? null))<span class="notification__sub">{{ $item['subtitle'] }}</span>@endif
                            </span>
                            @if (filled($item['time'] ?? null))<span class="notification__time">{{ $item['time'] }}</span>@endif
                        </{{ $url ? 'a' : 'div' }}>
                    </li>
                @endforeach
            </ul>
            @if ($safe($viewAllUrl))<a href="{{ $safe($viewAllUrl) }}" class="notifications__foot">View all notifications</a>@endif
        @endif
    </div>
</details>
