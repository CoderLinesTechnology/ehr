<?php

namespace Tests\Feature\Settings;

use App\Domain\Organization\UpdateOrganizationProfile;
use App\Domain\Platform\CreatedOrganization;
use App\Domain\Platform\OrganizationStatus;
use App\Domain\Settings\SettingsService;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Settings\Concerns\WorksInsideOrganizations;
use Tests\TestCase;

class OrganizationProfileTest extends TestCase
{
    use WorksInsideOrganizations;

    private CreatedOrganization $created;

    private Organization $org;

    private OrganizationMembership $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->created = $this->createOrganization(['name' => 'Accra Wellness Centre']);
        $this->org = $this->created->organization;
        $this->admin = $this->created->ownerMembership;
    }

    private function profile(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Accra Wellness Centre', 'legal_name' => null, 'email' => null, 'phone' => null, 'website' => null,
            'address_line1' => null, 'address_line2' => null, 'city' => null, 'region' => null, 'postal_code' => null,
            'country_code' => 'GH', 'timezone' => 'Africa/Accra', 'currency' => 'GHS', 'locale' => 'en',
        ];
    }

    /** @return list<string> the fields reported as changed */
    private function update(array $profile, array $formats = [], ?OrganizationMembership $as = null, ?Organization $organization = null): array
    {
        return $this->actAs($as ?? $this->admin, fn () => app(UpdateOrganizationProfile::class)($organization ?? $this->org, $profile, $formats));
    }

    private function row(): Organization
    {
        return Organization::query()->findOrFail($this->org->id);
    }

    #[Test]
    public function the_profile_is_updated_and_the_audit_holds_exactly_the_changed_fields(): void
    {
        $slug = $this->org->slug;
        $changed = $this->update($this->profile([
            'name' => '  Accra   Wellness & Therapy ', 'legal_name' => 'Accra Wellness Ltd', 'email' => 'hello@accra.example',
            'phone' => '+233 30 222 1111', 'website' => 'https://accra.example', 'address_line1' => '12 Oxford Street',
            'city' => 'Accra', 'region' => 'Greater Accra', 'postal_code' => 'GA-123',
        ]));

        $row = $this->row();
        $this->assertSame('Accra Wellness & Therapy', $row->name);
        $this->assertSame('hello@accra.example', $row->email);
        $this->assertSame('12 Oxford Street', $row->address_line1);
        $this->assertNull($row->address_line2);
        $this->assertSame($slug, $row->slug);                       // not part of the profile
        $this->assertSame(OrganizationStatus::Active, $row->status);   // neither is the status
        $this->assertEqualsCanonicalizing(['name', 'legal_name', 'email', 'phone', 'website', 'address_line1', 'city', 'region', 'postal_code'], $changed);
        $this->assertSame('Accra Wellness & Therapy', $this->org->name);   // the caller's instance is current

        $audit = $this->lastAudit('organization.profile_updated', $this->org);
        $this->assertEqualsCanonicalizing($changed, array_keys($audit->after));
        $this->assertSame('Accra Wellness Centre', $audit->before['name']);
        $this->assertSame('Accra Wellness & Therapy', $audit->after['name']);
        $this->assertArrayNotHasKey('timezone', $audit->after);   // untouched fields are not in the diff
        $this->assertSame($this->admin->user_id, $audit->actor_user_id);
        $this->assertSame($this->org->id, $audit->organization_id);

        // Saving the same values reports nothing and records nothing.
        $count = $this->auditCount('organization.profile_updated', $this->org);
        $this->assertSame([], $this->update($this->row()->only(array_keys($this->profile())) + $this->profile()));
        $this->assertSame($count, $this->auditCount('organization.profile_updated', $this->org));
    }

    #[Test]
    public function changing_timezone_or_currency_is_reported_so_the_screen_can_warn(): void
    {
        $changed = $this->update($this->profile(['timezone' => 'America/New_York', 'currency' => 'usd', 'country_code' => 'gh']));

        $this->assertEqualsCanonicalizing(['timezone', 'currency'], $changed);   // the country only changed case
        $row = $this->row();
        $this->assertSame('America/New_York', $row->timezone);
        $this->assertSame('USD', $row->currency);
        $this->assertSame('GH', $row->country_code);
        $this->assertSame('America/New_York', $this->org->timezone);

        $audit = $this->lastAudit('organization.profile_updated', $this->org);
        $this->assertEquals(['timezone' => 'Africa/Accra', 'currency' => 'GHS'], $audit->before);   // jsonb does not keep key order
        $this->assertEquals(['timezone' => 'America/New_York', 'currency' => 'USD'], $audit->after);
    }

    #[Test]
    public function regional_formats_are_saved_in_the_same_step_through_the_settings_registry(): void
    {
        $this->update($this->profile(), ['general.date_format' => 'Y-m-d', 'general.time_format' => 'g:i A', 'general.week_starts_on' => '7']);

        $settings = app(SettingsService::class);
        $this->assertSame('Y-m-d', $settings->organization($this->org, 'general.date_format'));
        $this->assertSame('g:i A', $settings->organization($this->org, 'general.time_format'));
        $this->assertSame('7', $settings->organization($this->org, 'general.week_starts_on'));

        $audits = AuditLog::query()->where('action', 'organization.setting_changed')->where('organization_id', $this->org->id)->get();
        $this->assertEqualsCanonicalizing(
            ['general.date_format', 'general.time_format', 'general.week_starts_on'],
            $audits->pluck('metadata.key')->all(),
        );
        $dateFormat = $audits->firstWhere('metadata.key', 'general.date_format');
        $this->assertSame(['general.date_format' => 'd/m/Y'], $dateFormat->before);   // Ghana's default
        $this->assertSame(['general.date_format' => 'Y-m-d'], $dateFormat->after);
    }

    #[Test]
    public function an_invalid_format_rolls_the_whole_update_back(): void
    {
        try {
            $this->update($this->profile(['name' => 'Renamed']), ['general.date_format' => 'dd-mm-yy']);
            $this->fail('An invalid format was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('general__date_format', $e->errors());
        }

        $this->assertSame('Accra Wellness Centre', $this->row()->name);
        $this->assertSame(0, $this->auditCount('organization.profile_updated', $this->org));
    }

    #[Test]
    public function only_regional_format_settings_belong_to_this_action(): void
    {
        foreach (['scheduling.min_notice_hours' => 5, 'platform.name' => 'Hijacked', 'general.nonsense' => 'x'] as $key => $value) {
            $this->assertRefused(fn () => $this->update($this->profile(['name' => 'Renamed']), [$key => $value]), 'unknown_setting');
        }

        $this->assertSame('Accra Wellness Centre', $this->row()->name);
        $this->assertSame(0, $this->auditCount('organization.profile_updated', $this->org));
    }

    public static function invalidProfiles(): array
    {
        return [
            'no name' => [['name' => '  '], 'invalid_name', 'name'],
            'name too long' => [['name' => str_repeat('x', 161)], 'invalid_name', 'name'],
            'bad email' => [['email' => 'nobody'], 'invalid_email', 'email'],
            'website without scheme' => [['website' => 'example.com'], 'invalid_website', 'website'],
            'script website' => [['website' => 'javascript:alert(1)'], 'invalid_website', 'website'],
            'unknown country' => [['country_code' => 'ZZ'], 'invalid_country', 'country_code'],
            'unknown timezone' => [['timezone' => 'Mars/Olympus'], 'invalid_timezone', 'timezone'],
            'unknown currency' => [['currency' => 'XXX'], 'invalid_currency', 'currency'],
            'unknown language' => [['locale' => 'tlh'], 'invalid_locale', 'locale'],
            'address too long' => [['address_line1' => str_repeat('x', 201)], 'too_long', 'address_line1'],
        ];
    }

    #[Test]
    #[DataProvider('invalidProfiles')]
    public function malformed_profiles_are_refused_and_nothing_changes(array $overrides, string $code, string $field): void
    {
        $this->assertRefused(fn () => $this->update($this->profile($overrides)), $code, $field);

        $this->assertSame('Accra Wellness Centre', $this->row()->name);
        $this->assertSame(0, $this->auditCount('organization.profile_updated', $this->org));
    }

    #[Test]
    public function only_someone_who_manages_organization_settings_can_edit_the_profile(): void
    {
        $clinician = $this->addStaff($this->org, 'clinician');
        $manager = $this->addStaff($this->org, 'practice_manager');

        $this->assertRefused(fn () => $this->update($this->profile(['name' => 'Nope']), as: $clinician), 'forbidden');

        $this->update($this->profile(['name' => 'By the manager']), as: $manager);   // allowed…
        $this->assertSame('By the manager', $this->row()->name);

        $this->revokeFromRole($this->org, 'practice_manager', 'organization.settings.manage');   // …until the permission goes
        $this->assertRefused(fn () => $this->update($this->profile(['name' => 'Again']), as: $manager), 'forbidden');
        $this->assertSame('By the manager', $this->row()->name);
    }

    #[Test]
    public function another_organization_cannot_be_edited_from_here(): void
    {
        $other = $this->createOrganization(['name' => 'Kumasi Clinic']);

        $this->expectException(ModelNotFoundException::class);

        try {
            $this->update($this->profile(['name' => 'Hijacked']), organization: $other->organization);
        } finally {
            $this->assertSame('Kumasi Clinic', Organization::query()->findOrFail($other->organization->id)->name);
            $this->assertSame(0, $this->auditCount('organization.profile_updated'));
        }
    }
}
