@php
    $safeUrl = static fn ($url) => filled($url) && ! preg_match('/^\s*(?:javascript|data|vbscript):/i', (string) $url) ? (string) $url : '#';
@endphp
<ul class="nav-list" role="list">
    @foreach ($items as $item)
        <li>
            <a href="{{ $safeUrl($item['url'] ?? null) }}" class="nav-link" @if (! empty($item['active'])) aria-current="page" @endif>
                <x-ui.icon :name="$item['icon'] ?? 'layers'" :size="18" />
                <span class="nav-link__label">{{ $item['label'] ?? '' }}</span>
                @if (! empty($item['badge']))<span class="nav-badge">{{ $item['badge'] }}</span>@endif
            </a>
        </li>
    @endforeach
</ul>
