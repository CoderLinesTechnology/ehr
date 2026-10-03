<?php

namespace Tests\Feature\Settings\Http;

use App\Domain\Identity\InviteStaff;
use App\Domain\Organization\SaveLocation;
use App\Domain\Organization\SaveService;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Settings\Concerns\WorksInsideOrganizations;
use Tests\TestCase;

/** Two organizations, each with an administrator; helpers to act as someone holding exactly the permissions a test needs. */
abstract class SettingsHttpTestCase extends TestCase
{
    use WorksInsideOrganizations;

    protected Organization $org;

    protected OrganizationMembership $admin;

    protected Organization $other;

    protected OrganizationMembership $otherAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $a = $this->createOrganization(['name' => 'Accra Wellness Centre']);
        $this->org = $a->organization;
        $this->admin = $a->ownerMembership;

        $b = $this->createOrganization(['name' => 'Kumasi Care Clinic']);
        $this->other = $b->organization;
        $this->otherAdmin = $b->ownerMembership;
    }

    protected function path(string $suffix = '', ?Organization $organization = null): string
    {
        return '/o/'.($organization ?? $this->org)->slug.'/settings'.($suffix === '' ? '' : '/'.ltrim($suffix, '/'));
    }

    protected function asAdmin(): static
    {
        return $this->actingAs(User::query()->findOrFail($this->admin->user_id));
    }

    protected function asOtherAdmin(): static
    {
        return $this->actingAs(User::query()->findOrFail($this->otherAdmin->user_id));
    }

    /** A signed-in member of $organization whose only role holds exactly $permissions. @return array{0: OrganizationMembership, 1: Role} */
    protected function memberWith(array $permissions, ?Organization $organization = null): array
    {
        $organization ??= $this->org;
        $role = $this->makeRole($organization, 'Only '.implode(',', $permissions).fake()->unique()->numberBetween(1, 99999), $permissions);
        $member = $this->addStaff($organization, 'staff');
        $this->giveRole($member, $role);
        $this->inTenant($organization, fn () => $member->roles()->detach(Role::query()->forOrganization($organization->id)->where('key', 'staff')->value('id')));
        $this->actingAs(User::query()->findOrFail($member->user_id));

        return [$member, $role];
    }

    protected function location(?Organization $organization = null, string $name = 'Main clinic'): Location
    {
        $organization ??= $this->org;
        $admin = $organization->is($this->org) ? $this->admin : $this->otherAdmin;

        return $this->actAs($admin, fn () => app(SaveLocation::class)(['name' => $name, 'timezone' => 'Africa/Accra', 'country_code' => 'GH']));
    }

    protected function service(?Organization $organization = null, string $name = 'Initial consultation'): Service
    {
        $organization ??= $this->org;
        $admin = $organization->is($this->org) ? $this->admin : $this->otherAdmin;

        return $this->actAs($admin, fn () => app(SaveService::class)([
            'name' => $name, 'duration_minutes' => 50, 'price' => '250.00', 'allows_in_person' => true, 'billing_behavior' => 'billable',
        ]));
    }

    /** A pending invitation; returns [membership, token]. @return array{0: OrganizationMembership, 1: string} */
    protected function invitation(string $email = 'new.person@example.com', ?Organization $organization = null, string $roleKey = 'staff'): array
    {
        $organization ??= $this->org;
        $admin = $organization->is($this->org) ? $this->admin : $this->otherAdmin;
        Notification::fake();
        $roleId = Role::query()->forOrganization($organization->id)->where('key', $roleKey)->value('id');
        $invite = $this->actAs($admin, fn () => app(InviteStaff::class)($email, [$roleId]));

        $token = $this->lastInvitation()['token'];
        $this->app['auth']->forgetGuards(); // setting the invitation up must not leave the administrator signed in

        return [$invite, $token];
    }
}
