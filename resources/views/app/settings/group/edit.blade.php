@php
    $route = $group === 'scheduling' ? 'app.settings.scheduling' : 'app.settings.clients';
@endphp
<x-layouts.app :title="$formTitle">
    @include('app.settings.partials.open', ['current' => $group])

    <form method="POST" action="{{ route($route.'.update') }}" class="set-form set-form--wide" data-submit-once novalidate>
        @csrf @method('PUT')
        @error('settings')<x-ui.alert tone="danger">{{ $message }}</x-ui.alert>@enderror
        <section class="set-card">
            <div class="set-fields">
                @foreach ($definitions as $key => $definition)
                    @php
                        $field = \App\Http\Requests\Settings\GroupSettingsRequest::settingField($key);
                        $name = 'settings['.$field.']';
                        $current = $values[$field] ?? null;
                    @endphp
                    @if ($definition->type === 'bool')
                        <div class="set-fields__full">
                            <x-ui.toggle :name="$name" :id="'s-'.$field" :label="$definition->label" :help="$definition->help" :checked="(bool) $current" />
                        </div>
                    @else
                        <x-ui.field :label="$definition->label" :name="$name" :id="'s-'.$field" :help="$definition->help">
                            @if (in_array($definition->type, ['enum'], true))
                                <x-ui.select :name="$name" :id="'s-'.$field" :options="$definition->options" :value="(string) $current" />
                            @elseif ($definition->type === 'time')
                                <x-ui.input type="time" :name="$name" :id="'s-'.$field" :value="$current" />
                            @elseif ($definition->type === 'int')
                                <x-ui.input type="number" :name="$name" :id="'s-'.$field" :value="$current" :min="$definition->min" :max="$definition->max" />
                            @else
                                <x-ui.input :name="$name" :id="'s-'.$field" :value="is_scalar($current) ? $current : ''" />
                            @endif
                        </x-ui.field>
                    @endif
                @endforeach
            </div>
        </section>
        <div class="form-actions"><x-ui.button type="submit">Save settings</x-ui.button></div>
    </form>
    @include('app.settings.partials.close')
</x-layouts.app>
