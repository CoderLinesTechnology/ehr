@php
    $editing = $role !== null;
    $chosen = old('permissions', $held);
    $chosen = is_array($chosen) ? $chosen : [];
@endphp
<x-layouts.app :title="$editing ? $role->name : 'Create role'">
    @include('app.settings.partials.open', ['current' => 'roles', 'panelTitle' => $editing ? $role->name : 'Create role', 'panelSub' => 'Roles are what people are given; permissions are what a role allows.'])

    @if ($readOnly)
        <p class="set-note" style="margin-bottom: 14px">{{ $role->is_locked ? $role->name.' is locked: it always holds every permission and cannot be edited.' : 'You can look at this role, but you cannot change it.' }}</p>
    @endif

    <form method="POST" action="{{ $editing ? route('app.settings.roles.update', ['role' => $role]) : route('app.settings.roles.store') }}" class="set-form set-form--wide" data-submit-once>
        @csrf
        @if ($editing) @method('PUT') @endif

        <section class="set-card">
            <div class="set-fields">
                <x-ui.field label="Name" name="name" :required="true"><x-ui.input name="name" :value="$role?->name" maxlength="100" :disabled="$readOnly" required /></x-ui.field>
                <x-ui.field label="Description" name="description" :optional="true"><x-ui.input name="description" :value="$role?->description" maxlength="500" :disabled="$readOnly" /></x-ui.field>
            </div>
        </section>

        <section class="set-card">
            <h3 class="set-card__title">Permissions</h3>
            <p class="set-card__sub">Tick what this role may do. You can only change permissions you hold yourself.</p>
            <x-ui.field name="permissions" style="margin-top: 8px">
                @foreach ($groups as $group => $permissions)
                    <fieldset class="set-perm-group">
                        <legend>{{ $group }}</legend>
                        <div class="set-perm-grid">
                            @foreach ($permissions as $key => $definition)
                                @php
                                    $isChosen = in_array($key, $chosen, true);
                                    $mayChange = ! $readOnly && isset($changeable[$key]);
                                @endphp
                                <x-ui.checkbox name="permissions[]" :id="'perm-'.md5($key)" :value="$key" :checked="$isChosen" :disabled="! $mayChange"
                                    :label="$definition['label'].($definition['sensitive'] ? ' (sensitive)' : '')" :help="$definition['description'] ?: null" />
                                @if (! $mayChange && $isChosen && ! $readOnly)<input type="hidden" name="permissions[]" value="{{ $key }}">@endif
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
            </x-ui.field>
        </section>

        <div class="form-actions">
            <x-ui.button variant="secondary" :href="route('app.settings.roles.index')">Back to roles</x-ui.button>
            @unless ($readOnly)<x-ui.button type="submit">{{ $editing ? 'Save role' : 'Create role' }}</x-ui.button>@endunless
        </div>
    </form>

    @if ($canDelete)
        <section class="set-card" style="margin-top: 9px">
            <h3 class="set-card__title">Delete this role</h3>
            <p class="set-card__sub">Only possible when nobody holds the role.</p>
            <div style="margin-top: 12px">
                <x-ui.confirm-form :action="route('app.settings.roles.destroy', ['role' => $role])" method="DELETE" :title="'Delete the role '.$role->name.'?'" message="This cannot be undone." confirm-label="Delete role" button-label="Delete role" button-icon="trash-2" />
            </div>
        </section>
    @endif
    @include('app.settings.partials.close')
</x-layouts.app>
