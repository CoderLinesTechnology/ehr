<?php

namespace App\Http\Requests\Platform;

use App\Domain\Settings\SettingDefinition;
use App\Domain\Settings\SettingsRegistry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The platform settings form. The value of each setting is validated by
 * SettingsService against its registry definition (the single source of the
 * rules); this request authorizes, collects the raw inputs and requires the
 * reason that goes in the audit log.
 *
 * Input names are the setting key with dots written as double underscores
 * (platform.name → platform__name): dots would be read as nested arrays.
 */
final class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('platform.settings.manage') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }

    public static function fieldName(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /**
     * Raw inputs for every platform setting, keyed by setting key.
     *
     * @return array<string, mixed>
     */
    public function settingValues(): array
    {
        $values = [];
        foreach (SettingsRegistry::forScope('platform') as $key => $definition) {
            $field = self::fieldName($key);
            $values[$key] = match ($definition->type) {
                SettingDefinition::TYPE_LIST => (array) $this->input($field, []),
                default => $this->input($field),
            };
        }

        return $values;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => 'Say why the settings are changing: the reason is kept in the audit log.'];
    }
}
