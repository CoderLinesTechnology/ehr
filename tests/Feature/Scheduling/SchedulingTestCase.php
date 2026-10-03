<?php

namespace Tests\Feature\Scheduling;

use App\Domain\Scheduling\AvailabilityModality;
use App\Domain\Scheduling\Modality;
use App\Domain\Scheduling\SlotFinder;
use App\Domain\Scheduling\SlotQuery;
use App\Domain\Scheduling\SlotResult;
use App\Domain\Settings\SettingsService;
use App\Domain\Tenancy\TenantContext;
use App\Models\AvailabilityRule;
use App\Models\Client;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Scheduling tests run inside one organization (Accra, UTC+0, no DST, unless a
 * test switches) with the clock frozen at Friday 2026-10-02 08:00 UTC.
 */
abstract class SchedulingTestCase extends TestCase
{
    protected Organization $organization;

    protected OrganizationMembership $adminMembership;

    protected User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:00:00', 'UTC'));
        $this->useOrganization('Africa/Accra');
    }

    /** Create a fresh organization in $timezone and make it the current tenant. */
    protected function useOrganization(string $timezone): Organization
    {
        $created = $this->createOrganization(['timezone' => $timezone]);
        $this->organization = $created->organization;
        $this->adminMembership = $created->ownerMembership;
        $this->actor = User::query()->findOrFail($created->ownerMembership->user_id);

        app(TenantContext::class)->set($this->organization, $this->adminMembership);

        return $this->organization;
    }

    protected function clinician(array $attributes = []): OrganizationMembership
    {
        return $this->addStaff($this->organization, 'clinician', $attributes);
    }

    protected function location(string $timezone = 'Africa/Accra', array $attributes = []): Location
    {
        return Location::factory()->create(['timezone' => $timezone] + $attributes);
    }

    /** A service provided by $providers (and, if given, offered only at $locations). */
    protected function service(array $attributes = [], array $providers = [], array $locations = []): Service
    {
        $service = Service::factory()->create($attributes + ['duration_minutes' => 60]);
        foreach ($providers as $provider) {
            $service->providers()->attach($provider->id);
        }
        foreach ($locations as $location) {
            $service->locations()->attach($location->id);
        }

        return $service;
    }

    protected function rule(OrganizationMembership $clinician, ?Location $location, int $weekday, string $start, string $end, array $attributes = []): AvailabilityRule
    {
        return AvailabilityRule::factory()->create(array_merge([
            'membership_id' => $clinician->id,
            'location_id' => $location?->id,
            'weekday' => $weekday,
            'start_time' => $start,
            'end_time' => $end,
            'modality' => $location === null ? AvailabilityModality::Telehealth : AvailabilityModality::InPerson,
        ], $attributes));
    }

    protected function client(array $attributes = []): Client
    {
        return Client::factory()->create($attributes);
    }

    protected function setting(string $key, mixed $value): void
    {
        app(SettingsService::class)->setOrganization($this->organization, [$key => $value], null);
    }

    protected function slots(
        Service $service,
        string $from,
        ?string $to = null,
        ?OrganizationMembership $clinician = null,
        ?Location $location = null,
        ?Modality $modality = null,
        bool $online = false,
    ): SlotResult {
        return app(SlotFinder::class)->find(new SlotQuery(
            service: $service,
            from: $from,
            to: $to ?? $from,
            clinician: $clinician,
            location: $location,
            modality: $modality,
            forOnlineBooking: $online,
        ));
    }

    /** @return list<string> slot starts as 'Y-m-d H:i' UTC */
    protected function startsUtc(SlotResult $result): array
    {
        return array_map(fn ($slot) => $slot->startsAt->utc()->format('Y-m-d H:i'), $result->slots);
    }

    /** @return list<string> slot starts as 'Y-m-d H:i' local to each slot */
    protected function startsLocal(SlotResult $result): array
    {
        return array_map(fn ($slot) => $slot->localStart()->format('Y-m-d H:i'), $result->slots);
    }

    /**
     * Run $callback and return the SQL it sent.
     *
     * @return list<string>
     */
    protected function queriesDuring(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $callback();
        } finally {
            DB::disableQueryLog();
        }

        return array_map(fn (array $query) => $query['query'], DB::getQueryLog());
    }
}
