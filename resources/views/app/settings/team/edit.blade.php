@php
    $name = $member->displayName();
    $editable = Gate::allows('update', $member);
    $held = old('roles', $heldRoleIds);
    $currentColor = strtolower((string) old('color', $member->color));
    $status = $member->status->value;
@endphp
<x-layouts.app :title="$name">
    @include('app.settings.partials.open', ['current' => 'team', 'panelTitle' => $member->professionalName(), 'panelSub' => $member->user?->email])

    <div class="set-stack">
        <form method="POST" action="{{ route('app.settings.team.update', ['member' => $member]) }}" class="set-form set-form--wide" data-submit-once>
            @csrf @method('PUT')
            <section class="set-card">
                <h3 class="set-card__title">Details</h3>
                <div class="set-fields" style="margin-top: 14px">
                    <x-ui.field label="Name prefix" name="name_prefix" :optional="true" help="Shown before their name on the calendar, for example Dr."><x-ui.input name="name_prefix" :value="$member->name_prefix" maxlength="20" :disabled="! $editable" /></x-ui.field>
                    <x-ui.field label="Job title" name="title" :optional="true"><x-ui.input name="title" :value="$member->title" maxlength="100" :disabled="! $editable" /></x-ui.field>
                    <x-ui.field label="Credentials" name="credentials" :optional="true"><x-ui.input name="credentials" :value="$member->credentials" maxlength="100" :disabled="! $editable" /></x-ui.field>
                    <div class="field">
                        <x-ui.checkbox name="is_provider" label="Sees clients (shows up on the calendar and can be booked)" :checked="$member->is_provider" :disabled="! $editable" />
                    </div>
                    <x-ui.field class="set-fields__full" label="Calendar colour" name="color" :optional="true" help="Appointments for this person use this colour on the calendar.">
                        <div class="set-swatches" data-choice-group="1">
                            <label class="set-swatch"><input type="radio" name="color" value="" @checked($currentColor === '') @disabled(! $editable)><i style="--c: #e2e8f0"></i>None</label>
                            @foreach ($colors as $hex => $label)
                                <label class="set-swatch"><input type="radio" name="color" value="{{ $hex }}" @checked($currentColor === $hex) @disabled(! $editable)><i style="--c: {{ $hex }}"></i>{{ $label }}</label>
                            @endforeach
                            @if ($currentColor !== '' && ! isset($colors[$currentColor]) && preg_match('/^#[0-9a-f]{6}$/', $currentColor))
                                <label class="set-swatch"><input type="radio" name="color" value="{{ $currentColor }}" checked @disabled(! $editable)><i style="--c: {{ $currentColor }}"></i>Current</label>
                            @endif
                        </div>
                    </x-ui.field>
                </div>
            </section>

            <section class="set-card">
                <h3 class="set-card__title">Roles</h3>
                <p class="set-card__sub">{{ $canChangeAccess ? 'What this person may do. Choose at least one.' : 'You cannot change the access of this person.' }}</p>
                <x-ui.field name="roles" style="margin-top: 14px">
                    <div class="stack stack--sm" data-choice-group="1">
                        @foreach ($roleOptions as $role)
                            @php $isHeld = in_array($role['id'], (array) $held, true); $locked = ! $canChangeAccess || ! $role['assignable']; @endphp
                            <x-ui.checkbox name="roles[]" :id="'role-'.$loop->index" :value="$role['id']" :label="$role['name']" :help="$role['description']" :checked="$isHeld" :disabled="$locked || ! $editable" />
                            @if ($isHeld && ($locked || ! $editable))<input type="hidden" name="roles[]" value="{{ $role['id'] }}">@endif
                        @endforeach
                    </div>
                </x-ui.field>
            </section>

            @if ($services !== [])
                <section class="set-card"><h3 class="set-card__title">Services provided</h3><p class="set-card__sub">{{ implode(', ', $services) }}</p></section>
            @endif

            <div class="form-actions">
                <x-ui.button variant="secondary" :href="route('app.settings.team.index')">Back to team</x-ui.button>
                @if ($editable)<x-ui.button type="submit">Save changes</x-ui.button>@endif
            </div>
        </form>

        <section class="set-card" aria-labelledby="access-title">
            <h3 class="set-card__title" id="access-title">Access</h3>
            <p class="set-card__sub">Currently <strong>{{ $status }}</strong>@if ($member->joined_at), joined {{ fmt()->date($member->joined_at) }}@endif.</p>
            @if ($isSelf)
                <p class="set-muted" style="margin-top: 10px">You cannot suspend or deactivate yourself.</p>
            @elseif (! $canChangeStatus)
                <p class="set-muted" style="margin-top: 10px">You cannot change the status of this person.</p>
            @else
                <div class="set-list__actions" style="margin-top: 14px">
                    @if ($status === 'active')
                        <x-ui.confirm-form :action="route('app.settings.team.status', ['member' => $member])" method="PATCH" :title="'Suspend '.$name.'?'" message="They cannot sign in to this organization until you reactivate them. Their records stay." confirm-label="Suspend" button-label="Suspend" button-variant="neutral" reason-field="reason" reason-label="Reason (optional)">
                            <input type="hidden" name="to" value="suspended">
                        </x-ui.confirm-form>
                    @else
                        <x-ui.confirm-form :action="route('app.settings.team.status', ['member' => $member])" method="PATCH" :title="'Reactivate '.$name.'?'" message="They can sign in again and use a staff seat." confirm-label="Reactivate" tone="primary" button-label="Reactivate" button-variant="secondary" reason-field="reason" reason-label="Reason (optional)">
                            <input type="hidden" name="to" value="active">
                        </x-ui.confirm-form>
                    @endif
                    @if ($status !== 'deactivated')
                        <x-ui.confirm-form :action="route('app.settings.team.status', ['member' => $member])" method="PATCH" :title="'Deactivate '.$name.'?'" message="They lose access to this organization and free a staff seat. Their past work stays on the record." confirm-label="Deactivate" button-label="Deactivate" reason-field="reason" reason-label="Reason (optional)">
                            <input type="hidden" name="to" value="deactivated">
                        </x-ui.confirm-form>
                    @endif
                </div>
            @endif
        </section>
    </div>
    @include('app.settings.partials.close')
</x-layouts.app>
