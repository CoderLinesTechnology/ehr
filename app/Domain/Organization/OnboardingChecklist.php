<?php

namespace App\Domain\Organization;

use App\Domain\Tenancy\TenantContext;
use App\Models\AvailabilityRule;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Service;
use Illuminate\Support\Facades\Route;

/**
 * What a new organization still has to set up before it can take bookings. The same four steps
 * decide `organizations.onboarding_completed_at` (RefreshOnboardingStatus) and drive the dashboard
 * checklist, so the two can never disagree.
 *
 * Reads the organization's own rows whatever tenant context the caller is in (jobs, console),
 * by running inside it.
 */
final class OnboardingChecklist
{
    /**
     * @return list<array{key: string, title: string, description: string, done: bool, url: ?string}>
     */
    public static function for(Organization $organization): array
    {
        $done = app(TenantContext::class)->runAs($organization, fn () => [
            'profile' => self::profileIsComplete($organization),
            'location' => Location::query()->active()->exists(),
            'service' => Service::query()->active()->exists(),
            'availability' => AvailabilityRule::query()
                ->where('is_active', true)
                ->whereIn('membership_id', OrganizationMembership::query()->providers()->select('id'))
                ->exists(),
        ]);

        $slug = ['organization' => $organization->slug];

        return [
            [
                'key' => 'profile',
                'title' => 'Complete your organization profile',
                'description' => 'Contact details and address, shown on messages and documents.',
                'done' => $done['profile'],
                'url' => self::url('app.settings.organization.edit', $slug),
            ],
            [
                'key' => 'location',
                'title' => 'Add a location',
                'description' => 'Where you see clients, with opening hours.',
                'done' => $done['location'],
                'url' => self::url('app.settings.locations.create', $slug),
            ],
            [
                'key' => 'service',
                'title' => 'Add a service',
                'description' => 'What you offer, how long it takes and what it costs.',
                'done' => $done['service'],
                'url' => self::url('app.settings.services.create', $slug),
            ],
            [
                'key' => 'availability',
                'title' => 'Set a provider\'s availability',
                'description' => 'When your clinicians can be booked.',
                'done' => $done['availability'],
                'url' => self::url('app.settings.availability.index', $slug),
            ],
        ];
    }

    /** @param  list<array{done: bool}>  $steps */
    public static function isComplete(array $steps): bool
    {
        return $steps !== [] && collect($steps)->every(fn (array $step) => $step['done']);
    }

    /**
     * Name, plus a way to reach the practice (phone or email) and where it is (address line and city).
     * Read from the database rather than from the instance passed in, which may be older than the last edit.
     */
    private static function profileIsComplete(Organization $organization): bool
    {
        $profile = Organization::query()->whereKey($organization->id)->first(['id', 'name', 'phone', 'email', 'address_line1', 'city']);

        return $profile !== null
            && filled($profile->name)
            && (filled($profile->phone) || filled($profile->email))
            && filled($profile->address_line1)
            && filled($profile->city);
    }

    /** @param array<string, string> $parameters */
    private static function url(string $route, array $parameters): ?string
    {
        return Route::has($route) ? route($route, $parameters) : null;
    }
}
