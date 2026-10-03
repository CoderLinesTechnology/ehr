@php
    $editing = $program !== null;
    $value = fn (string $field, $fallback = null) => $editing ? ($program->{$field} instanceof \BackedEnum ? $program->{$field}->value : $program->{$field}) : $fallback;
    $place = $editing ? ($program->is_online ? 'online' : ($program->location_id ?? '')) : '';
    $placeOptions = ['online' => 'Online'] + $locations;
@endphp
<x-layouts.app :title="$editing ? 'Edit program' : 'Create program'">
    @push('styles')
        @include('app.programs._head')
    @endpush

    <div class="pr pr--page">
        <x-ui.page-header :title="$editing ? 'Edit program' : 'Create program'" description="A program groups participants, levels of care and a schedule." icon="users-round" />

        <form method="POST" action="{{ $editing ? route('app.programs.update', ['program' => $program]) : route('app.programs.store') }}" class="pr-form" data-submit-once novalidate>
            @csrf
            @if ($editing) @method('PUT') @endif

            <div class="pr-form__grid">
                <x-ui.field class="pr-form__full" label="Program name" name="name" :required="true"><x-ui.input name="name" :value="$value('name')" maxlength="120" required /></x-ui.field>
                <x-ui.field class="pr-form__full" label="Description" name="description" :optional="true" help="Shown on the program card. Up to 1,000 characters. Do not write about individual clients here."><x-ui.textarea name="description" :value="$value('description')" rows="3" maxlength="1000" /></x-ui.field>
                <x-ui.field label="Start date" name="starts_on" :required="true"><x-ui.input type="date" name="starts_on" :value="$editing ? $program->starts_on->format('Y-m-d') : null" required /></x-ui.field>
                <x-ui.field label="End date" name="ends_on" :optional="true" help="Leave empty for an ongoing program."><x-ui.input type="date" name="ends_on" :value="$editing ? $program->ends_on?->format('Y-m-d') : null" /></x-ui.field>
                <x-ui.field label="Where it runs" name="place" :optional="true"><x-ui.select name="place" :value="$place" placeholder="No location" :options="$placeOptions" /></x-ui.field>
                <x-ui.field label="Colour" name="color" :required="true">
                    <x-ui.select name="color" :value="$value('color', 'blue')" :options="collect($colors)->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()" required />
                </x-ui.field>
                <x-ui.field class="pr-form__full" label="Icon" name="icon" :optional="true" help="Leave empty to use the colour's usual icon.">
                    <x-ui.select name="icon" :value="$value('icon')" placeholder="Default for the colour" :options="collect($icons)->mapWithKeys(fn ($i) => [$i => ucwords(str_replace('-', ' ', $i))])->all()" />
                </x-ui.field>
                @if ($canFlagSud || ($editing && $program->is_sud_program))
                    <div class="pr-form__full">
                        @if ($canFlagSud)
                            <x-ui.checkbox name="is_sud_program" label="Substance-use treatment program (42 CFR Part 2)" :checked="$editing && $program->is_sud_program"
                                help="Participants, attendance and history of this program are then visible only to people who may view substance-use program records." />
                        @else
                            <p class="pr-note">This program is flagged as substance-use treatment (42 CFR Part 2).</p>
                        @endif
                    </div>
                @endif
            </div>

            <div class="form-actions">
                <x-ui.button variant="secondary" :href="$editing ? route('app.programs.show', ['program' => $program]) : route('app.programs.index')">Cancel</x-ui.button>
                <x-ui.button type="submit">{{ $editing ? 'Save changes' : 'Create program' }}</x-ui.button>
            </div>
        </form>
    </div>
</x-layouts.app>
