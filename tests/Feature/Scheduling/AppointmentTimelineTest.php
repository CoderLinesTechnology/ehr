<?php

namespace Tests\Feature\Scheduling;

use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\Events\AppointmentRescheduled;
use App\Domain\Scheduling\Events\AppointmentScheduled;
use App\Domain\Scheduling\Events\AppointmentStatusChanged;
use App\Domain\Scheduling\Listeners\WriteAppointmentTimeline;
use App\Domain\Tenancy\TenantContext;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\OrganizationMembership;
use App\Models\TimelineEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

class AppointmentTimelineTest extends SchedulingTestCase
{
    private Appointment $appointment;

    private OrganizationMembership $clinician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinician = $this->addStaff($this->organization, 'clinician', [], User::factory()->create(['name' => 'Kwame Mensah']));
        $service = $this->service(['name' => 'Individual therapy', 'duration_minutes' => 50], [$this->clinician]);
        $client = Client::factory()->create();
        $this->appointment = Appointment::factory()->for($client)->for($service)->for($this->clinician, 'clinician')
            ->at(CarbonImmutable::parse('2026-10-06 14:00', 'UTC'))->create();
    }

    #[Test]
    public function the_listener_is_discovered_for_all_three_events(): void
    {
        Event::fake();

        foreach (['handleScheduled' => AppointmentScheduled::class, 'handleStatusChanged' => AppointmentStatusChanged::class] as $method => $event) {
            Event::assertListening($event, WriteAppointmentTimeline::class.'@'.$method);
        }
        Event::assertListening(AppointmentRescheduled::class, WriteAppointmentTimeline::class.'@handleRescheduled');
    }

    #[Test]
    public function a_scheduled_entry_names_service_clinician_and_local_time_only(): void
    {
        $queries = $this->queriesDuring(fn () => event(new AppointmentScheduled($this->appointment, $this->actor->id)));

        $entry = TimelineEntry::query()->sole();
        $this->assertSame('Individual therapy with Kwame Mensah on 06/10/2026 at 14:00 — scheduled', $entry->summary);
        $this->assertSame(['scheduling', 'appointment.scheduled', 'appointment', $this->appointment->id, $this->actor->id],
            [$entry->category, $entry->type, $entry->subject_type, $entry->subject_id, $entry->actor_user_id]);
        $this->assertSame($this->appointment->client_id, $entry->client_id);
        // Names (2), the client (1), the insert (1) — and the settings for date formats at most once.
        $this->assertLessThanOrEqual(5, count($queries));
    }

    #[Test]
    public function the_summary_follows_the_organization_date_and_time_formats_and_the_appointment_timezone(): void
    {
        $this->setting('general.date_format', 'm/d/Y');
        $this->setting('general.time_format', 'g:i A');
        $this->appointment->forceFill(['timezone' => 'America/New_York'])->save();

        event(new AppointmentScheduled($this->appointment, null));

        $this->assertSame('Individual therapy with Kwame Mensah on 10/06/2026 at 10:00 AM — scheduled', TimelineEntry::query()->sole()->summary);
    }

    #[Test]
    public function it_writes_into_the_appointments_organization_even_outside_a_tenant_context(): void
    {
        $tenant = app(TenantContext::class);
        $tenant->clear();

        event(new AppointmentStatusChanged($this->appointment, AppointmentStatus::Scheduled, AppointmentStatus::Confirmed, null, null));

        $this->assertFalse($tenant->has(), 'The previous (empty) context is restored.');
        $entry = $this->inTenant($this->organization, fn () => TimelineEntry::query()->sole());
        $this->assertSame('Individual therapy with Kwame Mensah on 06/10/2026 at 14:00 — confirmed', $entry->summary);
        $this->assertSame($this->organization->id, $entry->organization_id);
    }
}
