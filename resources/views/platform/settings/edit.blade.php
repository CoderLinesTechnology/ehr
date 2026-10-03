@php
    use App\Domain\Settings\SettingDefinition as D;
@endphp
<x-layouts.platform title="Platform settings">
    @include('platform.partials.assets')
    <x-ui.page-header title="Platform settings" description="Defaults and switches that apply across the platform. Every change is recorded with the reason you give." />

    <form method="POST" action="{{ route('platform.settings.update') }}" class="form pf-form" data-submit-once>
        @csrf
        @method('PUT')

        @foreach ($groups as $group)
            <x-ui.card :title="$group['title']" :description="$group['description']">
                <div class="form-grid">
                    @foreach ($group['items'] as $item)
                        @php
                            $def = $item['definition'];
                            $name = $item['field'];
                            $value = $item['value'];
                            $wide = in_array($def->type, [D::TYPE_TEXT, D::TYPE_LIST], true);
                        @endphp
                        @if ($def->type === D::TYPE_BOOL)
                            <div @class(['form-grid__full' => $wide])><x-ui.checkbox :name="$name" :label="$def->label" :help="$def->help" :checked="(bool) $value" /></div>
                        @elseif ($def->type === D::TYPE_LIST)
                            <x-ui.field :label="$def->label" :name="$name" :help="$def->help" class="form-grid__full">
                                <div class="pf-checks" data-choice-group="1">
                                    @foreach ($def->options as $optionValue => $optionLabel)
                                        <x-ui.checkbox :name="$name.'[]'" :value="$optionValue" :label="$optionLabel" :checked="in_array($optionValue, (array) $value, true)" />
                                    @endforeach
                                </div>
                            </x-ui.field>
                        @else
                            <x-ui.field :label="$def->label" :name="$name" :help="$def->help" :optional="$def->nullable" @class(['form-grid__full' => $wide])>
                                @if ($def->type === D::TYPE_ENUM)
                                    <x-ui.select :name="$name" :options="$def->options" :value="$value" />
                                @elseif ($def->type === D::TYPE_TIMEZONE)
                                    <x-ui.select :name="$name" :options="$timezones" :value="$value" />
                                @elseif ($def->type === D::TYPE_TEXT)
                                    <x-ui.textarea :name="$name" :value="$value" rows="3" />
                                @elseif ($def->type === D::TYPE_SECRET)
                                    <div class="pf-secret">
                                        <x-ui.input type="password" :name="$name" autocomplete="new-password" :placeholder="$item['secretIsSet'] ? 'A value is set. Type to replace it.' : 'Not set'" />
                                    </div>
                                @else
                                    <x-ui.input :type="match ($def->type) { D::TYPE_EMAIL => 'email', D::TYPE_URL => 'url', D::TYPE_INT => 'number', D::TYPE_TIME => 'time', default => 'text' }" :name="$name" :value="$value" />
                                @endif
                            </x-ui.field>
                        @endif
                    @endforeach
                </div>
            </x-ui.card>
        @endforeach

        <x-ui.card title="Reason for the change">
            <x-ui.field label="Reason" name="reason" :required="true" help="Kept in the audit log with the list of settings that changed.">
                <x-ui.input name="reason" required maxlength="500" />
            </x-ui.field>
        </x-ui.card>

        <div class="form-actions"><x-ui.button type="submit">Save settings</x-ui.button></div>
    </form>
</x-layouts.platform>
