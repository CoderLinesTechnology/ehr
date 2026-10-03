<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\Settings\Concerns\ValidatesRegistrySettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Scheduling and client settings forms. The group ("scheduling" or "clients") comes from the
 * route definition, never from input; rules, labels and the list of accepted keys all come from
 * SettingsRegistry, so a setting added to the registry is validated here with no further work.
 */
final class GroupSettingsRequest extends FormRequest
{
    use ValidatesRegistrySettings;

    public function authorize(): bool
    {
        return true;
    }

    public function group(): string
    {
        $group = $this->route()?->defaults['group'] ?? null;

        return is_string($group) && $this->organizationSettings($group) !== []
            ? $group
            : throw new \LogicException('The settings group of this route is not defined.');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->settingRules($this->organizationSettings($this->group()));
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->settingAttributes($this->organizationSettings($this->group()));
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectUnknownSettings($validator, $this->organizationSettings($this->group())),
            function (Validator $validator): void {
                // A calendar that ends before it starts would show nothing.
                $start = $this->input('settings.'.self::settingField('scheduling.calendar_day_start'));
                $end = $this->input('settings.'.self::settingField('scheduling.calendar_day_end'));
                if ($this->group() === 'scheduling' && is_string($start) && is_string($end) && ! $validator->errors()->hasAny([
                    'settings.'.self::settingField('scheduling.calendar_day_start'),
                    'settings.'.self::settingField('scheduling.calendar_day_end'),
                ]) && $end <= $start) {
                    $validator->errors()->add('settings.'.self::settingField('scheduling.calendar_day_end'), 'The calendar must end after the time it starts.');
                }
            },
        ];
    }

    /** @return array<string, mixed> setting key => value */
    public function values(): array
    {
        return $this->settingValuesFor($this->organizationSettings($this->group()));
    }
}
