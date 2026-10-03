<?php

namespace Tests\Feature\Scheduling\Http;

use App\Domain\Scheduling\Modality;
use App\Domain\Scheduling\ScheduleAppointment;
use App\Domain\Scheduling\ScheduleAppointmentData;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Scheduling\SchedulingTestCase;

/**
 * HTTP tests for the scheduling screens. Organization A (Accra, clock frozen at Fri 2026-10-02 08:00 UTC)
 * has two clinicians, a location, a service both provide, and one client each; organization B is a
 * different tenant with its own appointment, clinician, client and service.
 */
abstract class SchedulingHttpTestCase extends SchedulingTestCase
{
    protected OrganizationMembership $drA;

    protected OrganizationMembership $drB;

    protected Location $accra;

    protected Service $therapy;

    protected Client $clientA;

    protected Client $clientB;

    protected Organization $other;

    protected OrganizationMembership $otherClinician;

    protected Client $otherClient;

    protected Service $otherService;

    protected Appointment $otherAppointment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->drA = $this->clinician(['color' => '#307EF6']);
        $this->drB = $this->clinician(['color' => '#F59E0B']);
        $this->accra = $this->location();
        $this->therapy = $this->service(['name' => 'Therapy Session', 'duration_minutes' => 60, 'price_minor' => 30000, 'currency' => 'GHS'], [$this->drA, $this->drB]);
        $this->clientA = $this->client(['first_name' => 'Alice', 'last_name' => 'Alpha']);
        $this->clientB = $this->client(['first_name' => 'Bruno', 'last_name' => 'Bravo']);

        // A second tenant, entered only through inTenant() so the first stays the current one.
        $created = $this->createOrganization(['timezone' => 'Africa/Accra']);
        $this->other = $created->organization;
        $this->otherClinician = $this->addStaff($this->other, 'clinician');
        [$this->otherClient, $this->otherService, $this->otherAppointment] = $this->inTenant($this->other, function () use ($created) {
            $client = Client::factory()->create(['first_name' => 'Olga', 'last_name' => 'Outsider']);
            $service = Service::factory()->create(['name' => 'Other Service', 'duration_minutes' => 60]);
            $service->providers()->attach($this->otherClinician->id);
            $appointment = app(ScheduleAppointment::class)(new ScheduleAppointmentData(
                client: $client, service: $service, clinician: $this->otherClinician, modality: Modality::Telehealth,
                startsAt: CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'), actor: User::query()->find($created->ownerMembership->user_id),
            ));

            return [$client, $service, $appointment];
        });
    }

    /** Sign in as $member for the next request(s). */
    protected function as(OrganizationMembership $member): static
    {
        return $this->actingAs(User::query()->findOrFail($member->user_id));
    }

    protected function url(string $name, array $parameters = []): string
    {
        return route($name, ['organization' => $this->organization->slug] + $parameters);
    }

    protected function book(OrganizationMembership $clinician, Client $client, string $startsUtc, Modality $modality = Modality::Telehealth, ?Service $service = null): Appointment
    {
        return app(ScheduleAppointment::class)(new ScheduleAppointmentData(
            client: $client,
            service: $service ?? $this->therapy,
            clinician: $clinician,
            modality: $modality,
            startsAt: CarbonImmutable::parse($startsUtc, 'UTC'),
            location: $modality === Modality::InPerson ? $this->accra : null,
            actor: $this->actor,
        ));
    }

    /** Number of SQL statements the callback sent. */
    protected function queryCount(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $callback();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }
}
