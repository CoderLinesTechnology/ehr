<?php

namespace App\Domain\Settings;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditLogger;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Reads and writes platform and organization settings through the registry.
 * Values are loaded once per request (scoped binding) and read at call time,
 * so an administrator's change applies on the next request.
 */
final class SettingsService
{
    /** @var array<string, mixed>|null */
    private ?array $platform = null;

    /** @var array<string, array<string, mixed>> organization id => values */
    private array $organizations = [];

    public function __construct(private readonly AuditLogger $audit) {}

    public function platform(string $key): mixed
    {
        $definition = $this->definition($key, 'platform');
        $this->platform ??= PlatformSetting::query()->pluck('value', 'key')->all();

        return $this->present($definition, $this->platform[$key] ?? null);
    }

    public function organization(Organization|string $organization, string $key): mixed
    {
        $definition = $this->definition($key, 'organization');
        $id = $organization instanceof Organization ? $organization->id : $organization;

        $this->organizations[$id] ??= OrganizationSetting::query()
            ->where('organization_id', $id)
            ->pluck('value', 'key')
            ->all();

        return $this->present($definition, $this->organizations[$id][$key] ?? null);
    }

    /** Whether a secret setting has a stored value (secrets are write-only). */
    public function platformSecretIsSet(string $key): bool
    {
        $this->definition($key, 'platform');
        $this->platform ??= PlatformSetting::query()->pluck('value', 'key')->all();

        return ($this->platform[$key] ?? null) !== null;
    }

    /**
     * Validate and persist platform settings, auditing each change.
     *
     * @param  array<string, mixed>  $values  key => raw input
     */
    public function setPlatform(array $values, ?User $actor): void
    {
        $validated = $this->validate($values, 'platform');

        DB::transaction(function () use ($validated, $actor) {
            foreach ($validated as $key => $value) {
                $definition = SettingsRegistry::get($key);
                $before = $this->platform($key);
                if ($definition->isSecret() && ($value === null || $value === '')) {
                    continue; // empty secret input = keep the current value
                }
                if (! $definition->isSecret() && $before === $value) {
                    continue;
                }

                if ($value === null) {
                    PlatformSetting::query()->whereKey($key)->delete(); // back to the default
                } else {
                    PlatformSetting::query()->updateOrCreate(
                        ['key' => $key],
                        ['value' => $this->store($definition, $value), 'updated_by_user_id' => $actor?->id],
                    );
                }

                $this->audit->record(
                    'platform.setting_changed',
                    before: [$key => $definition->isSecret() ? '[redacted]' : $before],
                    after: [$key => $definition->isSecret() ? '[redacted]' : $value],
                    metadata: ['key' => $key],
                    context: AuditContext::Platform,
                );
            }
        });

        $this->platform = null;
    }

    /**
     * @param  array<string, mixed>  $values  key => raw input
     */
    public function setOrganization(Organization $organization, array $values, ?User $actor): void
    {
        $validated = $this->validate($values, 'organization');

        DB::transaction(function () use ($organization, $validated, $actor) {
            foreach ($validated as $key => $value) {
                $definition = SettingsRegistry::get($key);
                $before = $this->organization($organization, $key);
                if ($before === $value) {
                    continue;
                }

                $row = OrganizationSetting::query()->where('organization_id', $organization->id)->where('key', $key);
                if ($value === null) {
                    $row->delete(); // back to the default
                } elseif ($row->exists()) {
                    $row->update(['value' => json_encode($this->store($definition, $value)), 'updated_by_user_id' => $actor?->id, 'updated_at' => now()]);
                } else {
                    OrganizationSetting::query()->insert([
                        'organization_id' => $organization->id, 'key' => $key,
                        'value' => json_encode($this->store($definition, $value)),
                        'updated_by_user_id' => $actor?->id, 'updated_at' => now(),
                    ]);
                }

                $this->audit->record(
                    'organization.setting_changed',
                    subject: $organization,
                    before: [$key => $before],
                    after: [$key => $value],
                    metadata: ['key' => $key],
                );
            }
        });

        unset($this->organizations[$organization->id]);
    }

    /**
     * Write initial values without auditing each one (organization creation).
     *
     * @param  array<string, mixed>  $values
     */
    public function seedOrganization(Organization $organization, array $values): void
    {
        $rows = [];
        foreach ($this->validate($values, 'organization') as $key => $value) {
            if ($value !== null) {
                $rows[] = [
                    'organization_id' => $organization->id, 'key' => $key,
                    'value' => json_encode($this->store(SettingsRegistry::get($key), $value)),
                    'updated_at' => now(),
                ];
            }
        }
        OrganizationSetting::query()->upsert($rows, ['organization_id', 'key'], ['value', 'updated_at']);

        unset($this->organizations[$organization->id]);
    }

    /** Forget memoised values (tests, long-running workers). */
    public function flush(): void
    {
        $this->platform = null;
        $this->organizations = [];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> key => cast value
     */
    private function validate(array $values, string $scope): array
    {
        $rules = [];
        $data = [];
        foreach ($values as $key => $value) {
            $definition = $this->definition($key, $scope);
            $field = str_replace('.', '__', $key);
            $data[$field] = $value;
            $rules[$field] = $definition->validationRules();
            if ($definition->elementRules() !== []) {
                $rules[$field.'.*'] = $definition->elementRules();
            }
            if ($definition->isSecret()) {
                $rules[$field] = ['nullable', 'string', 'max:2000'];
            }
        }

        $validated = Validator::make($data, $rules, [], $this->attributeNames(array_keys($values)))->validate();

        $result = [];
        foreach ($values as $key => $_) {
            $result[$key] = SettingsRegistry::get($key)->cast($validated[str_replace('.', '__', $key)] ?? null);
        }

        return $result;
    }

    /** @param list<string> $keys @return array<string, string> */
    private function attributeNames(array $keys): array
    {
        $names = [];
        foreach ($keys as $key) {
            $names[str_replace('.', '__', $key)] = mb_strtolower(SettingsRegistry::get($key)->label);
        }

        return $names;
    }

    private function definition(string $key, string $scope): SettingDefinition
    {
        $definition = SettingsRegistry::get($key);
        if ($definition->scope !== $scope) {
            throw new InvalidArgumentException("Setting [{$key}] is not a {$scope} setting.");
        }

        return $definition;
    }

    private function present(SettingDefinition $definition, mixed $stored): mixed
    {
        if ($stored === null) {
            return $definition->default;
        }

        if ($definition->isSecret()) {
            return Crypt::decryptString($stored);
        }

        return $stored;
    }

    private function store(SettingDefinition $definition, mixed $value): mixed
    {
        return $definition->isSecret() && $value !== null ? Crypt::encryptString((string) $value) : $value;
    }
}
