<x-layouts.app title="Roles & Permissions">
    @if ($canManage)
        @push('settings-actions')<x-ui.button icon="plus" :href="route('app.settings.roles.create')">Create role</x-ui.button>@endpush
    @endif
    @include('app.settings.partials.open', ['current' => 'roles'])

    <section class="set-card set-card--flush" aria-label="Roles">
        <ul class="set-list">
            @foreach ($roles as $role)
                <li>
                    <div class="set-list__main">
                        <p class="set-list__title">{{ $role->name }}
                            @if ($role->is_locked)<x-ui.badge tone="outline" icon="lock">Locked</x-ui.badge>@elseif ($role->is_system)<x-ui.badge tone="neutral">Built in</x-ui.badge>@endif
                        </p>
                        <p class="set-list__meta">{{ $role->description ?: 'No description' }}</p>
                        <p class="set-list__meta">{{ $role->members_count }} {{ \Illuminate\Support\Str::plural('member', $role->members_count) }} · {{ $role->is_locked ? 'every permission' : $role->permissions_count.' '.\Illuminate\Support\Str::plural('permission', $role->permissions_count) }}</p>
                    </div>
                    <div class="set-list__actions">
                        <x-ui.button variant="secondary" size="sm" :icon="$role->is_locked || ! $canManage ? 'eye' : 'pencil'" :href="route('app.settings.roles.edit', ['role' => $role])">{{ $role->is_locked || ! $canManage ? 'View' : 'Edit' }}</x-ui.button>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
    @include('app.settings.partials.close')
</x-layouts.app>
