<?php

namespace App\Domain\Organization;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\AccessGuard;
use App\Domain\Shared\DomainException;
use App\Models\Service;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates a service (no $service given) or edits one (a service of the current organization).
 *
 * Money enters as decimal strings in the form the person typed it ("250.50") and is converted
 * to integer minor units by App\Support\Money::toMinor() — here, at the edge of the domain —
 * in the service's currency: the organization's for a new service, the service's own for an
 * existing one (it never changes, even after the organization's currency does). No float ever
 * touches a price. A price change applies to bookings made afterwards only: every appointment
 * snapshots the price it was booked at.
 *
 * Providers (active clinicians) and locations (active) are synced in the same transaction; an
 * empty location list means "every location". See ServiceLinks for what is refused and what is kept.
 * Services are never deleted (appointments reference them): deactivate with ChangeServiceStatus.
 *
 * Audit: `service.created` or `service.updated` (before/after of what changed, including the
 * names of providers and locations when those changed).
 */
final class SaveService
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly ServiceLinks $links,
        private readonly AuditLogger $audit,
        private readonly RefreshOnboardingStatus $onboarding,
    ) {}

    /**
     * @param  array<string, mixed>  $input  see ServiceData (price, late_cancellation_fee and no_show_fee are decimal strings),
     *                                       plus provider_ids and location_ids
     *
     * @throws DomainException
     */
    public function __invoke(array $input, ?Service $service = null): Service
    {
        $this->guard->requirePermission('services.manage');
        $organization = $this->guard->organization();

        if ($service !== null) {
            $this->guard->assertInOrganization($service);
        }

        return DB::transaction(function () use ($organization, $input, $service) {
            $locked = $service === null
                ? null
                : Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();

            $attributes = ServiceData::attributes($input, $locked?->currency ?? $organization->currency);

            $taken = Service::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower($attributes['name'])])
                ->when($locked, fn ($query) => $query->whereKeyNot($locked->id))
                ->exists();
            if ($taken) {
                throw $this->nameTaken();
            }

            $providerIds = (array) ($input['provider_ids'] ?? []);
            $locationIds = (array) ($input['location_ids'] ?? []);

            if ($locked === null) {
                $saved = new Service;
                $saved->forceFill($attributes + ['currency' => $organization->currency, 'is_active' => true]);
                $this->save($saved);

                [$providers, $locations] = array_values($this->links->sync($saved, $providerIds, $locationIds));

                $this->audit->record(
                    'service.created',
                    $saved,
                    after: $attributes + [
                        'currency' => $organization->currency,
                        'is_active' => true,
                        'providers' => $providers[1],
                        'locations' => $locations[1],
                    ],
                    summary: "Added the service {$saved->name}",
                );
            } else {
                $saved = $locked;
                $saved->forceFill($attributes);
                [$before, $after] = AuditDiff::of($saved);
                $this->save($saved);

                foreach ($this->links->sync($saved, $providerIds, $locationIds) as $kind => [$was, $now]) {
                    if ($was !== $now) {
                        $before[$kind] = $was;
                        $after[$kind] = $now;
                    }
                }

                if ($after !== []) {
                    $this->audit->record(
                        'service.updated',
                        $saved,
                        $before,
                        $after,
                        metadata: ['currency' => $saved->currency],
                        summary: "Updated the service {$saved->name}",
                    );
                }

                $service->setRawAttributes($saved->getAttributes(), true);
            }

            ($this->onboarding)($organization);

            return $service ?? $saved;
        });
    }

    private function save(Service $service): void
    {
        try {
            $service->save();
        } catch (UniqueConstraintViolationException) {
            throw $this->nameTaken();
        }
    }

    private function nameTaken(): DomainException
    {
        return new DomainException('You already have a service with that name.', 'service_name_taken', 'name');
    }
}
