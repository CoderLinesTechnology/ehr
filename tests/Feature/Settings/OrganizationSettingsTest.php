<?php

namespace Tests\Feature\Settings;

use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsService;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Settings\Concerns\WorksInsideOrganizations;
use Tests\TestCase;

/**
 * The scheduling and client settings forms save through SettingsService (the registry validates and audits).
 * These tests pin the behaviours those screens depend on: unknown keys are refused, values are validated
 * against their definitions, every change is audited, and one organization's values never reach another.
 */
class OrganizationSettingsTest extends TestCase
{
    use WorksInsideOrganizations;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $created = $this->createOrganization();
        $this->org = $created->organization;
        $this->user = User::query()->findOrFail($created->ownerMembership->user_id);
        $this->actingAs($this->user);
    }

    private function settings(): SettingsService
    {
        return app(SettingsService::class);
    }

    #[Test]
    public function the_scheduling_and_client_groups_are_declared_by_the_registry(): void
    {
        $scheduling = array_keys(SettingsRegistry::forScope('organization', 'scheduling'));
        $clients = array_keys(SettingsRegistry::forScope('organization', 'clients'));

        $this->assertContains('scheduling.default_duration_minutes', $scheduling);
        $this->assertContains('scheduling.cancellation_notice_hours', $scheduling);
        $this->assertContains('clients.require_contact', $clients);
        $this->assertSame([], array_intersect($scheduling, $clients));
        foreach ([...$scheduling, ...$clients] as $key) {
            $this->assertSame('organization', SettingsRegistry::get($key)->scope);
        }
    }

    #[Test]
    public function values_are_saved_cast_to_their_types_and_every_change_is_audited(): void
    {
        $this->settings()->setOrganization($this->org, [
            'scheduling.min_notice_hours' => '12',
            'scheduling.allow_overbooking' => '0',
            'scheduling.slot_interval_minutes' => '30',
            'scheduling.calendar_day_start' => '08:30',
            'clients.require_date_of_birth' => '1',
        ], $this->user);

        $this->assertSame(12, $this->settings()->organization($this->org, 'scheduling.min_notice_hours'));
        $this->assertFalse($this->settings()->organization($this->org, 'scheduling.allow_overbooking'));
        $this->assertSame('30', $this->settings()->organization($this->org, 'scheduling.slot_interval_minutes'));
        $this->assertSame('08:30', $this->settings()->organization($this->org, 'scheduling.calendar_day_start'));
        $this->assertTrue($this->settings()->organization($this->org, 'clients.require_date_of_birth'));

        $audit = $this->lastAudit('organization.setting_changed', $this->org);
        $this->assertNotNull($audit);
        $this->assertSame($this->user->id, $audit->actor_user_id);
        $this->assertSame(5, $this->auditCount('organization.setting_changed', $this->org));

        // The same value again records nothing.
        $this->settings()->setOrganization($this->org, ['scheduling.min_notice_hours' => '12'], $this->user);
        $this->assertSame(5, $this->auditCount('organization.setting_changed', $this->org));
    }

    #[Test]
    public function unknown_keys_and_platform_keys_are_refused(): void
    {
        foreach (['scheduling.made_up', 'platform.name', 'registration.mode', 'not_even_dotted'] as $key) {
            try {
                $this->settings()->setOrganization($this->org, [$key => 'x'], $this->user);
                $this->fail("The key [{$key}] was accepted.");
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        // One bad key refuses the whole save: nothing else is written.
        try {
            $this->settings()->setOrganization($this->org, ['scheduling.min_notice_hours' => '5', 'scheduling.made_up' => 'x'], $this->user);
            $this->fail('A save containing an unknown key went through.');
        } catch (InvalidArgumentException) {
            $this->assertSame(24, $this->settings()->organization($this->org, 'scheduling.min_notice_hours'));   // still the default
            $this->assertSame(0, $this->auditCount('organization.setting_changed', $this->org));
        }
    }

    #[Test]
    public function values_are_validated_against_their_definitions(): void
    {
        $bad = [
            'scheduling.min_notice_hours' => '1000',          // above the maximum
            'scheduling.default_duration_minutes' => '2',      // below the minimum
            'scheduling.slot_interval_minutes' => '7',         // not one of the options
            'scheduling.calendar_day_start' => '8am',          // not H:i
            'clients.require_contact' => 'maybe',              // not a boolean
        ];

        foreach ($bad as $key => $value) {
            try {
                $this->settings()->setOrganization($this->org, [$key => $value], $this->user);
                $this->fail("The value [{$value}] was accepted for [{$key}].");
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }

        $this->assertSame(0, $this->auditCount('organization.setting_changed', $this->org));
    }

    #[Test]
    public function one_organizations_settings_never_reach_another(): void
    {
        $other = $this->createOrganization()->organization;

        $this->settings()->setOrganization($this->org, ['scheduling.min_notice_hours' => '2'], $this->user);

        $this->assertSame(2, $this->settings()->organization($this->org, 'scheduling.min_notice_hours'));
        $this->assertSame(24, $this->settings()->organization($other, 'scheduling.min_notice_hours'));
        $this->assertSame(0, $this->auditCount('organization.setting_changed', $other));
        $this->assertSame(1, $this->auditCount('organization.setting_changed', $this->org));
    }
}
