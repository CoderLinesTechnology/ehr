<?php

namespace App\Domain\Scheduling;

use App\Domain\Audit\AuditLogger;
use App\Domain\Scheduling\Support\BookingRules;
use App\Domain\Scheduling\Support\TenantGuard;
use App\Domain\Scheduling\Support\WallClock;
use App\Domain\Shared\DomainException;
use App\Domain\Tenancy\TenantContext;
use App\Models\AvailabilityRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates or updates a clinician's recurring availability window. The
 * clinician must be an active provider, the location active, and any service
 * restriction a subset of what the clinician provides. Audited with before/after.
 */
final class SaveAvailabilityRule
{
    private const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly BookingRules $rules,
    ) {}

    public function __invoke(AvailabilityRuleData $data, ?AvailabilityRule $rule = null): AvailabilityRule
    {
        $organization = $this->tenant->organizationOrFail();
        TenantGuard::assertOwned($organization->id, $data->membership, $data->location, $rule);

        $this->validate($data);
        $serviceIds = $this->serviceIds($data);

        return DB::transaction(function () use ($data, $rule, $serviceIds) {
            $rule ??= new AvailabilityRule;
            $before = $rule->exists ? self::snapshot($rule, self::currentServiceIds($rule)) : null;

            $rule->fill([
                'membership_id' => $data->membership->id,
                'location_id' => $data->location?->id,
                'weekday' => $data->weekday,
                'start_time' => self::normalizeTime($data->startTime),
                'end_time' => self::normalizeTime($data->endTime),
                'modality' => $data->modality,
                'repeat_every_weeks' => $data->repeatEveryWeeks,
                'effective_from' => $data->effectiveFrom,
                'effective_until' => $data->effectiveUntil,
                'is_bookable_online' => $data->isBookableOnline,
                'is_active' => $data->isActive,
            ])->save();

            $rule->services()->sync($serviceIds);

            $this->audit->record(
                'availability.rule_saved',
                subject: $rule,
                before: $before,
                after: self::snapshot($rule, $serviceIds),
                summary: sprintf('Availability %s: %s %s–%s', $before === null ? 'added' : 'updated',
                    self::WEEKDAYS[$data->weekday], substr(self::normalizeTime($data->startTime), 0, 5), substr(self::normalizeTime($data->endTime), 0, 5)),
            );

            return $rule;
        });
    }

    /** @return array<string, mixed> */
    public static function snapshot(AvailabilityRule $rule, array $serviceIds): array
    {
        $attributes = $rule->getAttributes();
        sort($serviceIds);

        return [
            'membership_id' => $attributes['membership_id'] ?? null,
            'location_id' => $attributes['location_id'] ?? null,
            'weekday' => isset($attributes['weekday']) ? (int) $attributes['weekday'] : null,
            'start_time' => $attributes['start_time'] ?? null,
            'end_time' => $attributes['end_time'] ?? null,
            'modality' => $attributes['modality'] ?? null,
            'repeat_every_weeks' => isset($attributes['repeat_every_weeks']) ? (int) $attributes['repeat_every_weeks'] : null,
            'effective_from' => $attributes['effective_from'] ?? null,
            'effective_until' => $attributes['effective_until'] ?? null,
            'is_bookable_online' => (bool) ($attributes['is_bookable_online'] ?? false),
            'is_active' => (bool) ($attributes['is_active'] ?? false),
            'service_ids' => array_values($serviceIds),
        ];
    }

    /** @return list<string> */
    public static function currentServiceIds(AvailabilityRule $rule): array
    {
        return DB::table('availability_rule_services')
            ->where('organization_id', $rule->organization_id)
            ->where('availability_rule_id', $rule->id)
            ->orderBy('service_id')
            ->pluck('service_id')
            ->all();
    }

    private function validate(AvailabilityRuleData $data): void
    {
        if (! $this->rules->isBookableClinician($data->membership)) {
            throw new DomainException('Only active clinicians can have availability.', 'clinician_unavailable', 'membership_id');
        }

        if ($data->weekday < 1 || $data->weekday > 7) {
            throw new DomainException('Choose a day of the week.', 'invalid_weekday', 'weekday');
        }

        if (! WallClock::isTime($data->startTime) || WallClock::secondsOfDay($data->startTime) >= 86400) {
            throw new DomainException('Enter a valid start time.', 'invalid_time', 'start_time');
        }

        if (! WallClock::isTime($data->endTime)) {
            throw new DomainException('Enter a valid end time.', 'invalid_time', 'end_time');
        }

        if (WallClock::secondsOfDay($data->endTime) <= WallClock::secondsOfDay($data->startTime)) {
            throw new DomainException('The end time must be after the start time.', 'invalid_window', 'end_time');
        }

        if ($data->repeatEveryWeeks < 1 || $data->repeatEveryWeeks > 8) {
            throw new DomainException('Repeat every 1 to 8 weeks.', 'invalid_repeat', 'repeat_every_weeks');
        }

        if (! WallClock::isDate($data->effectiveFrom)) {
            throw new DomainException('Enter a valid start date.', 'invalid_date', 'effective_from');
        }

        if ($data->effectiveUntil !== null && ! WallClock::isDate($data->effectiveUntil)) {
            throw new DomainException('Enter a valid end date.', 'invalid_date', 'effective_until');
        }

        if ($data->effectiveUntil !== null && $data->effectiveUntil < $data->effectiveFrom) {
            throw new DomainException('The end date cannot be before the start date.', 'invalid_range', 'effective_until');
        }

        if ($data->modality !== AvailabilityModality::Telehealth && $data->location === null) {
            throw new DomainException('Choose a location for in-person availability.', 'location_required', 'location_id');
        }

        if ($data->location !== null && ! $data->location->is_active) {
            throw new DomainException('This location is closed.', 'location_inactive', 'location_id');
        }
    }

    /** @return list<string> the restriction, checked against the services the clinician provides */
    private function serviceIds(AvailabilityRuleData $data): array
    {
        $ids = array_values(array_unique($data->serviceIds));
        $refused = new DomainException('Choose only services this clinician provides.', 'services_not_provided', 'service_ids');

        if ($ids === []) {
            return [];
        }

        foreach ($ids as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                throw $refused;
            }
        }

        $provided = DB::table('service_providers')
            ->where('organization_id', $data->membership->organization_id)
            ->where('membership_id', $data->membership->id)
            ->whereIn('service_id', $ids)
            ->count();

        if ($provided !== count($ids)) {
            throw $refused;
        }

        return $ids;
    }

    private static function normalizeTime(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
