<?php

namespace App\Http\Requests\Settings\Concerns;

use App\Domain\Settings\SettingDefinition;
use App\Domain\Settings\SettingsRegistry;
use Illuminate\Validation\Validator;

/**
 * Forms built from SettingsRegistry post their values as `settings[<key>]`, with the dot of the
 * key written as a double underscore (`settings[scheduling__min_notice_hours]`) because a dot is
 * the nesting separator in validation rules. Rules come from the registry's own definitions, and
 * anything posted under `settings` that the group does not declare is a validation error.
 *
 * @mixin \Illuminate\Foundation\Http\FormRequest
 */
trait ValidatesRegistrySettings
{
    /** The form field name for a setting key. */
    public static function settingField(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /**
     * @param  array<string, SettingDefinition>  $definitions
     * @return array<string, array<int, mixed>>
     */
    protected function settingRules(array $definitions): array
    {
        $rules = ['settings' => ['required', 'array']];

        foreach ($definitions as $key => $definition) {
            $field = 'settings.'.self::settingField($key);
            $rules[$field] = $definition->validationRules();
            if ($definition->elementRules() !== []) {
                $rules[$field.'.*'] = $definition->elementRules();
            }
        }

        return $rules;
    }

    /**
     * @param  array<string, SettingDefinition>  $definitions
     * @return array<string, string>
     */
    protected function settingAttributes(array $definitions): array
    {
        $names = [];
        foreach ($definitions as $key => $definition) {
            $names['settings.'.self::settingField($key)] = mb_strtolower($definition->label);
        }

        return $names;
    }

    /**
     * Rejects any key under `settings` that this group does not declare.
     *
     * @param  array<string, SettingDefinition>  $definitions
     */
    protected function rejectUnknownSettings(Validator $validator, array $definitions): void
    {
        $posted = $this->input('settings');
        if (! is_array($posted)) {
            return;
        }

        $known = array_map(self::settingField(...), array_keys($definitions));

        foreach (array_keys($posted) as $field) {
            if (! in_array((string) $field, $known, true)) {
                $validator->errors()->add('settings', 'One of the submitted settings is not recognised, so nothing was saved.');

                return;
            }
        }
    }

    /**
     * The validated values keyed by real setting key (dots restored).
     *
     * @param  array<string, SettingDefinition>  $definitions
     * @return array<string, mixed>
     */
    protected function settingValuesFor(array $definitions): array
    {
        $validated = (array) $this->validated('settings');
        $values = [];

        foreach (array_keys($definitions) as $key) {
            $field = self::settingField($key);
            if (array_key_exists($field, $validated)) {
                $values[$key] = $validated[$field];
            }
        }

        return $values;
    }

    /** @return array<string, SettingDefinition> */
    protected function organizationSettings(string $group): array
    {
        return SettingsRegistry::forScope('organization', $group);
    }
}
