<?php

namespace Database\Factories;

use App\Domain\Scheduling\AvailabilityModality;
use App\Models\AvailabilityRule;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Requires a tenant context. Production code goes through SaveAvailabilityRule.
 * Default: Mondays 09:00–17:00 in person, every week, effective from a year ago.
 *
 * @extends Factory<AvailabilityRule>
 */
class AvailabilityRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'membership_id' => OrganizationMembership::factory(),
            'location_id' => Location::factory(),
            'weekday' => 1,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'modality' => AvailabilityModality::InPerson,
            'repeat_every_weeks' => 1,
            'effective_from' => CarbonImmutable::now('UTC')->subYear()->toDateString(),
            'effective_until' => null,
            'is_bookable_online' => true,
            'is_active' => true,
        ];
    }

    /** ISO weekday: 1 = Monday … 7 = Sunday. */
    public function on(int $weekday): static
    {
        return $this->state(fn () => ['weekday' => $weekday]);
    }

    /** Wall-clock window, 'HH:MM'. */
    public function between(string $start, string $end): static
    {
        return $this->state(fn () => ['start_time' => $start, 'end_time' => $end]);
    }

    /** Telehealth only, without a location: times are in the organization's timezone. */
    public function telehealth(): static
    {
        return $this->state(fn () => ['modality' => AvailabilityModality::Telehealth, 'location_id' => null]);
    }

    public function anyModality(): static
    {
        return $this->state(fn () => ['modality' => AvailabilityModality::Any]);
    }

    /** Only these services (otherwise: every service the clinician provides). */
    public function restrictedTo(Service ...$services): static
    {
        return $this->afterCreating(function (AvailabilityRule $rule) use ($services) {
            DB::table('availability_rule_services')->insert(array_map(fn (Service $service) => [
                'organization_id' => $rule->organization_id,
                'availability_rule_id' => $rule->id,
                'service_id' => $service->id,
            ], $services));
        });
    }
}
