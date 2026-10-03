<?php

namespace App\Domain\Saas;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates or edits a plan and its feature matrix. Plans are never deleted:
 * a plan that should no longer be sold is deactivated.
 *
 * Editing a plan never touches an existing subscription's snapshot (price,
 * currency, interval). Feature and limit values are read live from the plan, so
 * a change to them reaches every organization on the plan at once.
 */
final class SavePlan
{
    private const KEY_PATTERN = '/^[a-z][a-z0-9_-]{1,39}$/';

    /** Plan columns an administrator edits, besides the key (immutable after creation). */
    private const ATTRIBUTES = ['name', 'description', 'price_minor', 'currency', 'billing_interval', 'trial_days', 'is_public', 'is_active', 'sort'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * @param  array{key?: string, name: string, description?: ?string, price_minor: int, currency: string, billing_interval: string,
     *               trial_days: int, is_public: bool, is_active: bool, sort: int}  $attributes
     * @param  array<string, bool>  $features  boolean feature key => enabled
     * @param  array<string, ?int>  $limits  limit feature key => value (NULL = unlimited)
     */
    public function __invoke(?Plan $plan, array $attributes, array $features, array $limits, ?User $actor): Plan
    {
        $this->assertValid($plan, $attributes, $features, $limits);

        try {
            $saved = DB::transaction(fn () => $plan === null
                ? $this->create($attributes, $features, $limits)
                : $this->update($plan, $attributes, $features, $limits));
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('That plan key is already in use.', 'plan_key_taken', 'key');
        }

        // Plan values are read live by every organization on the plan.
        $this->entitlements->flush();

        return $saved;
    }

    /** @param array<string, mixed> $attributes @param array<string, bool> $features @param array<string, ?int> $limits */
    private function create(array $attributes, array $features, array $limits): Plan
    {
        $plan = new Plan;
        $this->write($plan, $attributes);
        $plan->forceFill(['key' => $attributes['key']])->save();

        $this->writeFeatures($plan, $features, $limits);

        $this->audit->record(
            'plan.created',
            subject: $plan,
            after: $this->describe($plan) + ['features' => $this->enabledKeys($features), 'limits' => $this->limitValues($limits)],
            summary: "Plan {$plan->name} created",
            context: AuditContext::Platform,
        );

        return $plan;
    }

    /** @param array<string, mixed> $attributes @param array<string, bool> $features @param array<string, ?int> $limits */
    private function update(Plan $plan, array $attributes, array $features, array $limits): Plan
    {
        /** @var Plan $locked */
        $locked = Plan::query()->lockForUpdate()->findOrFail($plan->id);

        if ($locked->is_active && ! $attributes['is_active']
            && ! Plan::query()->where('is_active', true)->whereKeyNot($locked->id)->exists()) {
            throw new DomainException('At least one plan must stay active so new organizations can be created.', 'last_active_plan', 'is_active');
        }

        $existing = PlanFeature::query()->where('plan_id', $locked->id)->get()->keyBy('feature_key');
        $before = $this->describe($locked);

        $this->write($locked, $attributes);
        $changedBefore = [];
        $changedAfter = [];
        foreach ($locked->getDirty() as $column => $value) {
            if ($column === 'updated_at') {
                continue;
            }
            $changedBefore[$column] = $before[$column] ?? $locked->getRawOriginal($column);
            $changedAfter[$column] = $value instanceof \BackedEnum ? $value->value : $value;
        }
        $locked->save();

        $featureBefore = [];
        $featureAfter = [];
        foreach ($features as $key => $enabled) {
            $was = $existing->get($key)?->enabled ?? false;
            if ($was !== $enabled) {
                $featureBefore[$key] = $was;
                $featureAfter[$key] = $enabled;
            }
        }
        foreach ($limits as $key => $value) {
            $row = $existing->get($key);
            $was = $row?->limit_value;
            if ($row === null || $was !== $value) {
                $featureBefore[$key] = $row === null ? 'unset' : ($was ?? 'unlimited');
                $featureAfter[$key] = $value ?? 'unlimited';
            }
        }

        $this->writeFeatures($locked, $features, $limits);

        if ($changedAfter !== [] || $featureAfter !== []) {
            $this->audit->record(
                'plan.updated',
                subject: $locked,
                before: $changedBefore + ($featureBefore === [] ? [] : ['features' => $featureBefore]),
                after: $changedAfter + ($featureAfter === [] ? [] : ['features' => $featureAfter]),
                summary: "Plan {$locked->name} updated",
                context: AuditContext::Platform,
            );
        }

        $plan->setRawAttributes($locked->getAttributes(), true);

        return $plan;
    }

    /** @param array<string, mixed> $attributes */
    private function write(Plan $plan, array $attributes): void
    {
        $plan->fill([
            'name' => trim($attributes['name']),
            'description' => ($attributes['description'] ?? null) !== null && trim($attributes['description']) !== '' ? trim($attributes['description']) : null,
            'billing_interval' => $attributes['billing_interval'],
            'trial_days' => $attributes['trial_days'],
            'is_public' => $attributes['is_public'],
            'is_active' => $attributes['is_active'],
            'sort' => $attributes['sort'],
        ]);
        // Money columns are not mass-assignable: written here only.
        $plan->forceFill([
            'price_minor' => $attributes['price_minor'],
            'currency' => strtoupper($attributes['currency']),
        ]);
    }

    /** @param array<string, bool> $features @param array<string, ?int> $limits */
    private function writeFeatures(Plan $plan, array $features, array $limits): void
    {
        // Only keys the features table knows: the plan_features foreign key would refuse the rest.
        $known = array_flip(Feature::query()->pluck('key')->all());

        $rows = [];
        foreach ($features as $key => $enabled) {
            if (isset($known[$key])) {
                $rows[] = ['plan_id' => $plan->id, 'feature_key' => $key, 'enabled' => $enabled, 'limit_value' => null];
            }
        }
        foreach ($limits as $key => $value) {
            if (isset($known[$key])) {
                $rows[] = ['plan_id' => $plan->id, 'feature_key' => $key, 'enabled' => false, 'limit_value' => $value];
            }
        }

        if ($rows !== []) {
            PlanFeature::query()->upsert($rows, ['plan_id', 'feature_key'], ['enabled', 'limit_value']);
        }
    }

    /** @return array<string, mixed> */
    private function describe(Plan $plan): array
    {
        return [
            'name' => $plan->name,
            'description' => $plan->description,
            'price_minor' => $plan->price_minor,
            'currency' => $plan->currency,
            'billing_interval' => $plan->billing_interval,
            'trial_days' => $plan->trial_days,
            'is_public' => $plan->is_public,
            'is_active' => $plan->is_active,
            'sort' => $plan->sort,
        ];
    }

    /** @param array<string, bool> $features @return list<string> */
    private function enabledKeys(array $features): array
    {
        return array_keys(array_filter($features));
    }

    /** @param array<string, ?int> $limits @return array<string, int|string> */
    private function limitValues(array $limits): array
    {
        return array_map(fn (?int $value) => $value ?? 'unlimited', $limits);
    }

    /** @param array<string, mixed> $attributes @param array<string, bool> $features @param array<string, ?int> $limits */
    private function assertValid(?Plan $plan, array $attributes, array $features, array $limits): void
    {
        foreach (self::ATTRIBUTES as $required) {
            if (! array_key_exists($required, $attributes)) {
                throw new \InvalidArgumentException("Missing plan attribute [{$required}].");
            }
        }

        if (trim((string) $attributes['name']) === '' || mb_strlen((string) $attributes['name']) > 120) {
            throw new DomainException('Give the plan a name of up to 120 characters.', 'invalid_name', 'name');
        }
        if (! is_int($attributes['price_minor']) || $attributes['price_minor'] < 0) {
            throw new DomainException('The price cannot be negative.', 'invalid_price', 'price');
        }
        if (! preg_match('/^[A-Za-z]{3}$/', (string) $attributes['currency'])) {
            throw new DomainException('Use a three-letter currency code.', 'invalid_currency', 'currency');
        }
        if (! in_array($attributes['billing_interval'], ['month', 'year'], true)) {
            throw new DomainException('Billing interval must be monthly or yearly.', 'invalid_interval', 'billing_interval');
        }
        if (! is_int($attributes['trial_days']) || $attributes['trial_days'] < 0 || $attributes['trial_days'] > 365) {
            throw new DomainException('Trial length must be between 0 and 365 days.', 'invalid_trial', 'trial_days');
        }
        if (! is_int($attributes['sort']) || $attributes['sort'] < 0 || $attributes['sort'] > 32767) {
            throw new DomainException('Sort order must be a whole number between 0 and 32767.', 'invalid_sort', 'sort');
        }

        if ($plan === null && ! preg_match(self::KEY_PATTERN, (string) ($attributes['key'] ?? ''))) {
            throw new DomainException('The plan key may use lowercase letters, numbers, hyphens and underscores, and must start with a letter.', 'invalid_key', 'key');
        }

        $booleans = FeatureRegistry::booleanOptions();
        foreach ($features as $key => $enabled) {
            if (! isset($booleans[$key]) || ! is_bool($enabled)) {
                throw new DomainException('The plan contains a feature that does not exist.', 'unknown_feature');
            }
        }
        foreach ($limits as $key => $value) {
            if (! in_array($key, FeatureRegistry::keys(), true) || ! FeatureRegistry::isLimit($key)) {
                throw new DomainException('The plan contains a limit that does not exist.', 'unknown_feature');
            }
            if ($value !== null && (! is_int($value) || $value < 0)) {
                throw new DomainException('Limits must be whole numbers of zero or more, or unlimited.', 'invalid_limit');
            }
        }
    }
}
