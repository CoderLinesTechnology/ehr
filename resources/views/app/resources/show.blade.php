@php use App\Domain\Resources\ResourceStatus; use App\Domain\Resources\ResourceType; @endphp
<x-layouts.app :title="$resource->title">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/screens/resources.css') }}?v={{ filemtime(public_path('css/screens/resources.css')) }}">
    @endpush

    <div class="res res--doc">
        <x-ui.breadcrumbs :items="[['label' => 'Resources', 'url' => route('app.resources.index')], ['label' => $resource->type->plural(), 'url' => route('app.resources.index', ['type' => $resource->type->value])], ['label' => $resource->title]]" />

        <article class="res-doc">
            <header class="res-doc__head">
                <span class="res-tile res-tile--{{ $resource->type->value }} res-tile--lg"><x-ui.icon :name="$resource->type->icon()" :size="28" /></span>
                <div class="res-doc__titles">
                    <p class="res-doc__kind">
                        {{ $resource->type->label() }}
                        @if ($resource->status !== ResourceStatus::Published)<x-ui.badge :tone="$resource->status->tone()">{{ $resource->status->label() }}</x-ui.badge>@endif
                        @if ($canManage)<x-ui.badge tone="outline">{{ $resource->audience->label() }}</x-ui.badge>@endif
                    </p>
                    <h1>{{ $resource->title }}</h1>
                    <p class="res-doc__summary">{{ $resource->summary }}</p>
                    <p class="res-doc__meta">
                        @if ($resource->reading_minutes !== null)<span><x-ui.icon name="clock" :size="14" />{{ $resource->type->minutesLabel($resource->reading_minutes) }}</span>@endif
                        @if ($resource->published_at !== null)<span><x-ui.icon name="calendar" :size="14" />Published {{ fmt()->localDate($resource->published_at) }}</span>@endif
                    </p>
                </div>
                <div class="res-doc__actions">
                    @if ($resource->type === ResourceType::Video && $resource->external_url)
                        <x-ui.button icon="external-link" :href="$resource->external_url" target="_blank">Watch video</x-ui.button>
                    @endif
                    @if ($resource->hasFile())
                        <x-ui.button icon="file-text" :href="route('app.resources.file', ['resource' => $resource])" target="_blank">Open PDF</x-ui.button>
                    @endif
                    @if ($canManage)
                        <x-ui.button variant="secondary" icon="pencil" :href="route('app.resources.edit', ['resource' => $resource])">Edit</x-ui.button>
                        @if ($resource->status !== ResourceStatus::Published)
                            <form method="POST" action="{{ route('app.resources.publish', ['resource' => $resource]) }}" data-submit-once>@csrf<x-ui.button type="submit" variant="success">Publish</x-ui.button></form>
                        @endif
                        @if ($resource->status !== ResourceStatus::Archived)
                            <x-ui.confirm-form :action="route('app.resources.archive', ['resource' => $resource])" title="Archive this resource?" message="Archived resources are hidden from everyone but resource managers. You can publish it again later." confirm-label="Archive" button-label="Archive" button-variant="secondary" button-icon="archive" />
                        @endif
                    @endif
                </div>
            </header>

            @if ($paragraphs !== [])
                <div class="res-doc__body">
                    @foreach ($paragraphs as $paragraph)<p>@foreach (explode("\n", $paragraph) as $line){{ $line }}@if (! $loop->last)<br>@endif @endforeach</p>@endforeach
                </div>
            @elseif (! $resource->hasFile() && ! $resource->external_url)
                <p class="res-doc__empty">There is no content yet.</p>
            @endif

            @if ($resource->type === ResourceType::Video && $resource->external_url)
                <p class="res-doc__note"><x-ui.icon name="external-link" :size="14" />This video opens on another website in a new tab.</p>
            @endif
        </article>
    </div>
</x-layouts.app>
