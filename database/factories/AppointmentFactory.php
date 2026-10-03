<?php

namespace Database\Factories;

use App\Domain\Scheduling\AppointmentSource;
use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\CancellationKind;
use App\Domain\Scheduling\Modality;
use App\Domain\Tenancy\TenantContext;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\Service;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Requires a tenant context. Production code books through ScheduleAppointment;
 * this factory exists for tests and seeding. Unless given, the snapshot fields
 * follow the participants like a real booking: ends_at from the service
 * duration, price/currency from the service, timezone from the location (the
 * organization's for telehealth), record_environment from the client; and the
 * clinician is made a provider of the service.
 *
 * Pass instants through at() (or as UTC): Eloquent writes a datetime's wall
 * clock without its offset, and the database session reads it as UTC.
 *
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'service_id' => Service::factory(),
            'clinician_membership_id' => OrganizationMembership::factory(),
            'location_id' => Location::factory(),
            'modality' => Modality::InPerson,
            'status' => AppointmentStatus::Scheduled,
            'starts_at' => CarbonImmutable::now('UTC')->addWeek()->setTime(10, 0),
            'source' => AppointmentSource::Staff->value,
            'allow_overlap' => false,
            'late_cancellation' => false,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Appointment $appointment) {
            $attributes = $appointment->getAttributes();
            $service = fn () => Service::query()->findOrFail($attributes['service_id']);
            $derived = [];

            if (($attributes['ends_at'] ?? null) === null) {
                $derived['ends_at'] = $appointment->starts_at->utc()->addMinutes($service()->duration_minutes);
            }
            if (($attributes['price_minor'] ?? null) === null) {
                $derived['price_minor'] = $service()->price_minor;
            }
            if (($attributes['currency'] ?? null) === null) {
                $derived['currency'] = $service()->currency;
            }
            if (($attributes['record_environment'] ?? null) === null) {
                $derived['record_environment'] = Client::query()->whereKey($attributes['client_id'])->value('record_environment');
            }
            if (($attributes['timezone'] ?? null) === null) {
                $derived['timezone'] = ($attributes['location_id'] ?? null) !== null
                    ? Location::query()->whereKey($attributes['location_id'])->value('timezone')
                    : app(TenantContext::class)->organizationOrFail()->timezone;
            }

            $appointment->forceFill($derived);
        })->afterCreating(function (Appointment $appointment) {
            DB::table('service_providers')->insertOrIgnore([
                'organization_id' => $appointment->organization_id,
                'service_id' => $appointment->service_id,
                'membership_id' => $appointment->clinician_membership_id,
            ]);
        });
    }

    /** Starting at an instant (stored as UTC); the end follows the service duration. */
    public function at(DateTimeInterface $startsAt): static
    {
        return $this->state(fn () => [
            'starts_at' => CarbonImmutable::instance($startsAt)->utc(),
            'ends_at' => null,
        ]);
    }

    public function telehealth(): static
    {
        return $this->state(fn () => ['modality' => Modality::Telehealth, 'location_id' => null]);
    }

    /** A status, with its milestone column stamped. */
    public function status(AppointmentStatus $status): static
    {
        return $this->state(function () use ($status) {
            $state = ['status' => $status];
            if (($column = $status->timestampColumn()) !== null) {
                $state[$column] = CarbonImmutable::now('UTC');
            }

            return $state;
        });
    }

    public function cancelled(CancellationKind $kind = CancellationKind::Practice): static
    {
        return $this->status(AppointmentStatus::Cancelled)->state(fn () => ['cancellation_kind' => $kind->value]);
    }
}
