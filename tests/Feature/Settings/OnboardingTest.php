<?php

namespace Tests\Feature\Settings;

use App\Domain\Organization\ChangeLocationStatus;
use App\Domain\Organization\OnboardingChecklist;
use App\Domain\Organization\RefreshOnboardingStatus;
use App\Domain\Organization\SaveLocation;
use App\Domain\Organization\SaveService;
use App\Domain\Organization\UpdateOrganizationProfile;
use App\Domain\Platform\CreatedOrganization;
use App\Domain\Tenancy\TenantContext;
use App\Models\AvailabilityRule;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Settings\Concerns\WorksInsideOrganizations;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use WorksInsideOrganizations;

    private CreatedOrganization $created;

    private Organization $org;

    private OrganizationMembership $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->created = $this->createOrganization();
        $this->org = $this->created->organization;
        $this->admin = $this->created->ownerMembership;
    }

    /** @return array<string, bool> step key => done */
    private function steps(?Organization $organization = null): array
    {
        $organization ??= $this->org;

        return collect(OnboardingChecklist::for($organization))->pluck('done', 'key')->all();
    }

    private function completedAt(?Organization $organization = null)
    {
        return Organization::query()->findOrFail(($organization ?? $this->org)->id)->onboarding_completed_at;
    }

    private function completeProfile(?OrganizationMembership $as = null): void
    {
        $as ??= $this->admin;
        $organization = Organization::query()->findOrFail($as->organization_id);

        $this->actAs($as, fn () => app(UpdateOrganizationProfile::class)($organization, [
            'name' => $organization->name, 'email' => 'hello@practice.example', 'address_line1' => '1 High Street', 'city' => 'Accra',
            'country_code' => 'GH', 'timezone' => 'Africa/Accra', 'currency' => 'GHS', 'locale' => 'en',
        ]));
    }

    private function addLocation(): Location
    {
        return $this->actAs($this->admin, fn () => app(SaveLocation::class)(['name' => 'Main clinic']));
    }

    private function addService(): void
    {
        $this->actAs($this->admin, fn () => app(SaveService::class)([
            'name' => 'Therapy', 'duration_minutes' => 50, 'price' => '200', 'allows_in_person' => true, 'billing_behavior' => 'billable',
        ]));
    }

    /** Availability belongs to the scheduling module; here a rule is created directly, as that module would. */
    private function addAvailability(OrganizationMembership $provider, bool $active = true): AvailabilityRule
    {
        return $this->inTenant($this->org, fn () => AvailabilityRule::query()->create([
            'membership_id' => $provider->id, 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00',
            'modality' => 'telehealth', 'repeat_every_weeks' => 1, 'effective_from' => now()->toDateString(),
            'is_bookable_online' => true, 'is_active' => $active,
        ]));
    }

    #[Test]
    public function a_new_organization_has_four_open_steps_each_with_a_title_and_a_target(): void
    {
        $steps = OnboardingChecklist::for($this->org);

        $this->assertSame(['profile', 'location', 'service', 'availability'], array_column($steps, 'key'));
        $this->assertSame([false, false, false, false], array_column($steps, 'done'));
        foreach ($steps as $step) {
            $this->assertNotSame('', $step['title']);
            $this->assertNotSame('', $step['description']);
            $this->assertTrue($step['url'] === null || str_contains($step['url'], $this->org->slug));   // a link only where the screen exists
        }
        $this->assertFalse(OnboardingChecklist::isComplete($steps));
        $this->assertFalse(OnboardingChecklist::isComplete([]));
        $this->assertNull($this->completedAt());
    }

    #[Test]
    public function the_profile_step_needs_contact_details_and_an_address(): void
    {
        // The checklist reads the stored profile, so each combination is written to the database first.
        $check = function (array $attributes) {
            DB::table('organizations')->where('id', $this->org->id)
                ->update(['phone' => null, 'email' => null, 'address_line1' => null, 'city' => null, ...$attributes]);

            return $this->steps()['profile'];
        };

        $this->assertFalse($check([]));
        $this->assertFalse($check(['phone' => '+233 30 222 1111']));                                         // no address
        $this->assertFalse($check(['address_line1' => '1 High Street', 'city' => 'Accra']));                  // no way to reach them
        $this->assertFalse($check(['phone' => '+233 30 222 1111', 'address_line1' => '1 High Street']));      // no city
        $this->assertTrue($check(['phone' => '+233 30 222 1111', 'address_line1' => '1 High Street', 'city' => 'Accra']));
        $this->assertTrue($check(['email' => 'hello@practice.example', 'address_line1' => '1 High Street', 'city' => 'Accra']));
    }

    #[Test]
    public function only_active_locations_services_and_availability_of_active_providers_count(): void
    {
        $location = $this->addLocation();
        $this->addService();
        $this->assertSame(['profile' => false, 'location' => true, 'service' => true, 'availability' => false], $this->steps());

        // A location that is switched off no longer counts.
        $this->actAs($this->admin, fn () => app(ChangeLocationStatus::class)($location, false));
        $this->assertFalse($this->steps()['location']);

        // Availability: an inactive rule, a rule of someone who is not a provider and a rule of a deactivated provider do not count.
        $this->addAvailability($this->admin, active: false);
        $this->assertFalse($this->steps()['availability']);

        $receptionist = $this->addStaff($this->org, 'receptionist', ['is_provider' => false]);
        $this->addAvailability($receptionist);
        $this->assertFalse($this->steps()['availability']);

        $gone = $this->addStaff($this->org, 'clinician');
        $this->addAvailability($gone);
        $this->inTenant($this->org, fn () => $this->reload($gone)->forceFill(['status' => 'deactivated', 'deactivated_at' => now()])->save());
        $this->assertFalse($this->steps()['availability']);

        $this->addAvailability($this->admin);   // the owner is a provider
        $this->assertTrue($this->steps()['availability']);
    }

    #[Test]
    public function completing_the_last_step_through_a_domain_action_stamps_onboarding_completed_once(): void
    {
        $this->addAvailability($this->admin);
        $this->completeProfile();
        $this->addLocation();
        $this->assertNull($this->completedAt());   // still no service

        $this->addService();   // the last piece: this action refreshes the status itself

        $stamped = $this->completedAt();
        $this->assertNotNull($stamped);
        $audit = $this->lastAudit('organization.onboarding_completed', $this->org);
        $this->assertNotNull($audit);
        $this->assertSame($this->org->id, $audit->subject_id);

        // Idempotent, and it never un-completes: switching things off later leaves the stamp alone.
        $this->travel(2)->days();
        $this->assertTrue($this->actAs($this->admin, fn () => app(RefreshOnboardingStatus::class)($this->org)));
        $this->assertEquals($stamped, $this->completedAt());
        $this->assertSame(1, $this->auditCount('organization.onboarding_completed', $this->org));

        $location = Location::query()->withoutGlobalScopes()->where('organization_id', $this->org->id)->firstOrFail();
        $this->actAs($this->admin, fn () => app(ChangeLocationStatus::class)($location, false));
        $this->assertFalse($this->steps()['location']);
        $this->assertEquals($stamped, $this->completedAt());
    }

    #[Test]
    public function the_scheduling_module_can_finish_onboarding_by_calling_the_refresh_itself(): void
    {
        $this->completeProfile();
        $this->addLocation();
        $this->addService();
        $this->assertNull($this->completedAt());

        $this->addAvailability($this->admin);   // availability is written by another module…
        $this->assertNull($this->completedAt());

        $done = $this->actAs($this->admin, fn () => app(RefreshOnboardingStatus::class)($this->org));   // …which calls this

        $this->assertTrue($done);
        $this->assertNotNull($this->completedAt());
    }

    #[Test]
    public function an_incomplete_organization_is_reported_as_such_without_writing_anything(): void
    {
        $this->completeProfile();
        $this->addLocation();

        $this->assertFalse($this->actAs($this->admin, fn () => app(RefreshOnboardingStatus::class)($this->org)));
        $this->assertNull($this->completedAt());
        $this->assertSame(0, $this->auditCount('organization.onboarding_completed', $this->org));
    }

    #[Test]
    public function the_checklist_reads_the_organization_it_is_given_whatever_context_it_is_called_from(): void
    {
        $other = $this->createOrganization();
        $this->completeProfile();
        $this->addLocation();                                      // this organization: profile + location
        $this->actAs($other->ownerMembership, fn () => app(SaveService::class)([
            'name' => 'Theirs', 'duration_minutes' => 30, 'price' => '10', 'allows_in_person' => true, 'billing_behavior' => 'billable',
        ]));                                                       // the other one: only a service

        // From inside THIS organization's context, asking about the other organization…
        $fromHere = $this->actAs($this->admin, fn () => [
            'other' => collect(OnboardingChecklist::for($other->organization))->pluck('done', 'key')->all(),
            'mine' => collect(OnboardingChecklist::for($this->org))->pluck('done', 'key')->all(),
            'context' => app(TenantContext::class)->id(),
        ]);

        $this->assertSame(['profile' => false, 'location' => false, 'service' => true, 'availability' => false], $fromHere['other']);
        $this->assertSame(['profile' => true, 'location' => true, 'service' => false, 'availability' => false], $fromHere['mine']);
        $this->assertSame($this->org->id, $fromHere['context']);   // …and the caller's context is restored afterwards

        // And from no context at all (a job, the console).
        $this->assertSame(['profile' => true, 'location' => true, 'service' => false, 'availability' => false], $this->steps());
    }
}
