<?php

namespace App\Domain\Settings;

use Illuminate\Validation\Rule;

/**
 * One configurable business value. Defined in code (SettingsRegistry), stored
 * as data (platform_settings / organization_settings). Anything that would
 * change security or core logic is NOT a setting.
 */
final readonly class SettingDefinition
{
    public const TYPE_STRING = 'string';

    public const TYPE_TEXT = 'text';

    public const TYPE_BOOL = 'bool';

    public const TYPE_INT = 'int';

    public const TYPE_ENUM = 'enum';

    public const TYPE_TIMEZONE = 'timezone';

    public const TYPE_EMAIL = 'email';

    public const TYPE_URL = 'url';

    public const TYPE_TIME = 'time';

    public const TYPE_LIST = 'list';

    public const TYPE_SECRET = 'secret';

    /**
     * @param  'platform'|'organization'  $scope
     * @param  array<string, string>  $options  value => label (enum / list types)
     * @param  list<mixed>  $rules  extra validation rules
     */
    public function __construct(
        public string $key,
        public string $scope,
        public string $type,
        public mixed $default,
        public string $group,
        public string $label,
        public ?string $help = null,
        public array $options = [],
        public array $rules = [],
        public bool $nullable = false,
        public ?int $min = null,
        public ?int $max = null,
    ) {}

    public function isSecret(): bool
    {
        return $this->type === self::TYPE_SECRET;
    }

    /** @return list<mixed> */
    public function validationRules(): array
    {
        $base = $this->nullable ? ['nullable'] : ['required'];

        $typed = match ($this->type) {
            self::TYPE_STRING, self::TYPE_SECRET => ['string', 'max:255'],
            self::TYPE_TEXT => ['string', 'max:2000'],
            self::TYPE_BOOL => ['boolean'],
            self::TYPE_INT => array_values(array_filter([
                'integer',
                $this->min !== null ? 'min:'.$this->min : null,
                $this->max !== null ? 'max:'.$this->max : null,
            ])),
            self::TYPE_ENUM => [Rule::in(array_keys($this->options))],
            self::TYPE_TIMEZONE => [Rule::in(\DateTimeZone::listIdentifiers())],
            self::TYPE_EMAIL => ['email:rfc', 'max:254'],
            self::TYPE_URL => ['url:https,http', 'max:500'],
            self::TYPE_TIME => ['date_format:H:i'],
            self::TYPE_LIST => ['array'],
            default => [],
        };

        if ($this->type === self::TYPE_BOOL) {
            $base = ['required'];
        }

        if ($this->type === self::TYPE_LIST) {
            $base = ['present'];
        }

        return [...$base, ...$typed, ...$this->rules];
    }

    /** Rules for each element of a list setting. @return list<mixed> */
    public function elementRules(): array
    {
        return $this->type === self::TYPE_LIST && $this->options !== []
            ? ['string', Rule::in(array_keys($this->options))]
            : [];
    }

    /** Normalise a validated input value to its stored PHP type. */
    public function cast(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $this->nullable ? null : $this->default;
        }

        return match ($this->type) {
            self::TYPE_BOOL => filter_var($value, FILTER_VALIDATE_BOOL),
            self::TYPE_INT => (int) $value,
            self::TYPE_LIST => array_values(array_unique(array_map('strval', (array) $value))),
            default => is_string($value) ? trim($value) : $value,
        };
    }
}
