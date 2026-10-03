<?php

namespace App\Domain\Platform;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditLogger;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\DomainException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Saves the platform settings form. SettingsService does the work that matters
 * (validates every value against its registry definition, all or nothing, and
 * audits each changed key; a secret left empty keeps its stored value). This
 * adds the one thing the console requires on top: a reason, recorded once for
 * the whole save, listing which keys changed — never their values.
 */
final class UpdatePlatformSettings
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $values  raw input per setting key
     * @return list<string> the keys that changed (empty when nothing did)
     */
    public function __invoke(array $values, string $reason, ?User $actor): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A reason is required to change the platform settings.', 'reason_required', 'reason');
        }
        if (mb_strlen($reason) > 500) {
            throw new DomainException('The reason may not be longer than 500 characters.', 'reason_too_long', 'reason');
        }

        return DB::transaction(function () use ($values, $reason, $actor) {
            $before = $this->snapshot();

            $this->settings->setPlatform($values, $actor);

            $after = $this->snapshot();

            $changed = [];
            foreach ($after as $key => $value) {
                $definition = SettingsRegistry::get($key);
                $submitted = $values[$key] ?? null;
                $secretReplaced = $definition->isSecret() && is_string($submitted) && trim($submitted) !== '';

                if ($secretReplaced || ($before[$key] ?? null) !== $value) {
                    $changed[] = $key;
                }
            }

            if ($changed !== []) {
                $this->audit->record(
                    'platform.settings_updated',
                    after: ['changed' => $changed],
                    metadata: ['reason' => $reason],
                    summary: 'Platform settings updated ('.count($changed).' changed)',
                    context: AuditContext::Platform,
                );
            }

            return $changed;
        });
    }

    /**
     * Comparable values of every platform setting. A secret is represented by
     * whether it is set, so its value never leaves SettingsService here.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (SettingsRegistry::forScope('platform') as $key => $definition) {
            if ($definition->isSecret()) {
                $snapshot[$key] = $this->settings->platformSecretIsSet($key);

                continue;
            }

            $value = $this->settings->platform($key);
            if (is_array($value)) {
                sort($value);
            }
            $snapshot[$key] = $value;
        }

        return $snapshot;
    }
}
