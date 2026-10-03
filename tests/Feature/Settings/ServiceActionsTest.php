<?php

namespace Tests\Feature\Settings;

use App\Domain\Identity\MembershipStatus;
use App\Domain\Organization\ChangeServiceStatus;
use App\Domain\Organization\SaveService;
use App\Domain\Organization\UpdateOrganizationProfile;
use App\Domain\Platform\CreatedOrganization;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Service;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Settings\Concerns\WorksInsideOrganizations;
use Tests\TestCase;

class ServiceActionsTest extends TestCase
{
    use WorksInsideOrganizations;

    private CreatedOrganization $created;

    private Organization $org;

    private OrganizationMembership $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->created = $this->createOrganization();   // GHS
        $this->org = $this->created->organization;
        $this->admin = $this->created->ownerMembership;
    }

    /** The smallest valid service input, overridable. */
    private function input(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Individual therapy', 'duration_minutes' => 50, 'price' => '250.50',
            'allows_in_person' => true, 'allows_telehealth' => false, 'billing_behavior' => 'billable',
        ];
    }

    private function save(array $input, ?Service $service = null, ?OrganizationMembership $as = null): Service
    {
        return $this->actAs($as ?? $this->admin, fn () => app(SaveService::class)($input, $service));
    }

    private function toggle(Service $service, bool $active, ?OrganizationMembership $as = null): Service
    {
        return $this->actAs($as ?? $this->admin, fn () => app(ChangeServiceStatus::class)($service, $active));
    }

    private function row(Service $service): ?Service
    {
        return Service::query()->withoutGlobalScopes()->find($service->id);
    }

    /** @return list<string> */
    private function providerIds(Service $service): array
    {
        return DB::table('service_providers')->where('service_id', $service->id)->pluck('membership_id')->sort()->values()->all();
    }

    /** @return list<string> */
    private function locationIds(Service $service): array
    {
        return DB::table('service_locations')->where('service_id', $service->id)->pluck('location_id')->sort()->values()->all();
    }

    private function location(Organization $organization, array $attributes = []): Location
    {
        return $this->inTenant($organization, fn () => Location::factory()->create($attributes));
    }

    #[Test]
    public function a_service_is_created_with_its_price_in_minor_units_and_the_organizations_currency(): void
    {
        $service = $this->save($this->input([
            'description' => ' A fifty-minute session ', 'code' => '90837', 'late_cancellation_fee' => '50', 'no_show_fee' => '100.5',
            'allows_telehealth' => true, 'is_bookable_online' => true, 'requires_documentation' => true,
            'cancellation_notice_hours' => '48', 'color' => '#5B8DEF',
            'currency' => 'USD', 'is_active' => false, 'organization_id' => 'not-honoured',   // none of these can be set through the input
        ]));

        $row = $this->row($service);
        $this->assertSame($this->org->id, $row->organization_id);
        $this->assertSame(25050, $row->price_minor);
        $this->assertSame(5000, $row->late_cancellation_fee_minor);
        $this->assertSame(10050, $row->no_show_fee_minor);
        $this->assertSame('GHS', $row->currency);
        $this->assertTrue($row->is_active);
        $this->assertSame('A fifty-minute session', $row->description);
        $this->assertSame(50, $row->duration_minutes);
        $this->assertTrue($row->allows_in_person);
        $this->assertTrue($row->allows_telehealth);
        $this->assertTrue($row->is_bookable_online);
        $this->assertTrue($row->requires_documentation);
        $this->assertSame(48, $row->cancellation_notice_hours);
        $this->assertSame('#5b8def', $row->color);
        $this->assertSame('billable', $row->billing_behavior);

        $audit = $this->lastAudit('service.created', $this->org);
        $this->assertSame(25050, $audit->after['price_minor']);
        $this->assertSame('GHS', $audit->after['currency']);
        $this->assertSame($this->admin->user_id, $audit->actor_user_id);
    }

    #[Test]
    public function optional_fields_default_sensibly(): void
    {
        $row = $this->row($this->save($this->input(['price' => '0'])));

        $this->assertSame(0, $row->price_minor);   // free is a price
        $this->assertNull($row->late_cancellation_fee_minor);
        $this->assertNull($row->no_show_fee_minor);
        $this->assertNull($row->cancellation_notice_hours);   // → the organization's default
        $this->assertNull($row->color);
        $this->assertNull($row->description);
        $this->assertFalse($row->is_bookable_online);
        $this->assertFalse($row->requires_documentation);
    }

    public static function invalidPrices(): array
    {
        return [
            'letters' => ['abc'],
            'negative' => ['-5'],
            'exponent' => ['1e3'],
            'comma decimal' => ['250,50'],
            'too many decimals' => ['250.505'],
            'empty' => [''],
            'blank' => ['   '],
            'too large' => ['1234567890123'],
            'thousands separator' => ['1,250.00'],
            'currency symbol' => ['GHS 250'],
        ];
    }

    #[Test]
    #[DataProvider('invalidPrices')]
    public function a_price_must_be_a_plain_decimal_number(string $price): void
    {
        $this->assertRefused(fn () => $this->save($this->input(['price' => $price])), 'invalid_price', 'price');
        $this->assertSame(0, Service::query()->withoutGlobalScopes()->where('organization_id', $this->org->id)->count());
    }

    #[Test]
    public function fees_follow_the_same_rule(): void
    {
        $this->assertRefused(fn () => $this->save($this->input(['late_cancellation_fee' => '5,0'])), 'invalid_price', 'late_cancellation_fee');
        $this->assertRefused(fn () => $this->save($this->input(['no_show_fee' => '-1'])), 'invalid_price', 'no_show_fee');
    }

    #[Test]
    public function currencies_without_a_minor_unit_take_whole_amounts_only(): void
    {
        $uganda = $this->createOrganization(['currency' => 'UGX']);
        $admin = $uganda->ownerMembership;

        $this->assertSame(250, $this->row($this->save($this->input(['name' => 'A', 'price' => '250']), as: $admin))->price_minor);
        $this->assertSame(250, $this->row($this->save($this->input(['name' => 'B', 'price' => '250.0']), as: $admin))->price_minor);
        $this->assertRefused(fn () => $this->save($this->input(['name' => 'C', 'price' => '250.5']), as: $admin), 'invalid_price', 'price');
    }

    public static function invalidServices(): array
    {
        return [
            'no name' => [['name' => ''], 'invalid_name', 'name'],
            'name too long' => [['name' => str_repeat('x', 121)], 'invalid_name', 'name'],
            'description too long' => [['description' => str_repeat('x', 2001)], 'too_long', 'description'],
            'code too long' => [['code' => str_repeat('x', 21)], 'too_long', 'code'],
            'too short' => [['duration_minutes' => 4], 'invalid_duration', 'duration_minutes'],
            'too long a session' => [['duration_minutes' => 1441], 'invalid_duration', 'duration_minutes'],
            'duration not a number' => [['duration_minutes' => 'an hour'], 'invalid_duration', 'duration_minutes'],
            'no modality' => [['allows_in_person' => false, 'allows_telehealth' => false], 'no_modality', 'allows_in_person'],
            'unknown billing' => [['billing_behavior' => 'free'], 'invalid_billing', 'billing_behavior'],
            'notice too long' => [['cancellation_notice_hours' => 169], 'invalid_notice', 'cancellation_notice_hours'],
            'negative notice' => [['cancellation_notice_hours' => -1], 'invalid_notice', 'cancellation_notice_hours'],
            'bad colour' => [['color' => 'blue'], 'invalid_color', 'color'],
            'short colour' => [['color' => '#fff'], 'invalid_color', 'color'],
        ];
    }

    #[Test]
    #[DataProvider('invalidServices')]
    public function malformed_services_are_refused_and_nothing_is_stored(array $overrides, string $code, string $field): void
    {
        $this->assertRefused(fn () => $this->save($this->input($overrides)), $code, $field);

        $this->assertSame(0, Service::query()->withoutGlobalScopes()->where('organization_id', $this->org->id)->count());
        $this->assertSame(0, $this->auditCount('service.created', $this->org));
    }

    #[Test]
    public function providers_must_be_active_clinicians_of_this_organization(): void
    {
        $clinician = $this->addStaff($this->org, 'clinician');   // an active provider
        $notProvider = $this->addStaff($this->org, 'receptionist', ['is_provider' => false]);
        $suspended = $this->addStaff($this->org, 'clinician', ['status' => MembershipStatus::Suspended]);
        $other = $this->createOrganization();
        $foreign = $this->addStaff($other->organization, 'clinician');

        $service = $this->save($this->input(['provider_ids' => [$clinician->id, $clinician->id]]));
        $this->assertSame([$clinician->id], $this->providerIds($service));   // duplicates collapse
        $this->assertSame($this->org->id, DB::table('service_providers')->where('service_id', $service->id)->value('organization_id'));

        foreach ([$notProvider->id, $suspended->id, $foreign->id, (string) Str::uuid()] as $ineligibleId) {
            $this->assertRefused(fn () => $this->save($this->input(['name' => 'Other', 'provider_ids' => [$ineligibleId]])), 'invalid_provider', 'providers');
        }
        $this->assertRefused(fn () => $this->save($this->input(['name' => 'Other', 'provider_ids' => ['not-a-uuid']])), 'invalid_option', 'providers');

        $this->assertSame(1, Service::query()->withoutGlobalScopes()->where('organization_id', $this->org->id)->count());   // the refused ones were rolled back
        $this->assertSame(0, DB::table('service_providers')->where('membership_id', $foreign->id)->count());
    }

    #[Test]
    public function locations_must_be_active_locations_of_this_organization_and_none_means_all(): void
    {
        $osu = $this->location($this->org);
        $legon = $this->location($this->org);
        $closed = $this->location($this->org, ['is_active' => false]);
        $foreign = $this->location($this->createOrganization()->organization);

        $everywhere = $this->save($this->input(['name' => 'Everywhere']));
        $this->assertSame([], $this->locationIds($everywhere));   // no rows = every location

        $some = $this->save($this->input(['name' => 'Some', 'location_ids' => [$osu->id, $legon->id]]));
        $this->assertEqualsCanonicalizing([$osu->id, $legon->id], $this->locationIds($some));

        $this->assertRefused(fn () => $this->save($this->input(['name' => 'X', 'location_ids' => [$closed->id]])), 'invalid_location', 'locations');
        $this->assertRefused(fn () => $this->save($this->input(['name' => 'Y', 'location_ids' => [$osu->id, $foreign->id]])), 'invalid_location', 'locations');
        $this->assertSame(0, DB::table('service_locations')->where('location_id', $foreign->id)->count());
        $this->assertNull(Service::query()->withoutGlobalScopes()->whereIn('name', ['X', 'Y'])->first());
    }

    #[Test]
    public function an_update_changes_the_price_and_the_audit_shows_before_and_after(): void
    {
        $service = $this->save($this->input(['price' => '250.50']));

        $this->save($this->input(['price' => '300', 'name' => 'Individual therapy (50 min)']), $service);

        $this->assertSame(30000, $this->row($service)->price_minor);
        $this->assertSame(30000, $service->price_minor);   // the caller's instance is current
        $audit = $this->lastAudit('service.updated', $this->org);
        $this->assertSame(25050, $audit->before['price_minor']);
        $this->assertSame(30000, $audit->after['price_minor']);
        $this->assertSame('Individual therapy', $audit->before['name']);
        $this->assertSame('GHS', $audit->metadata['currency']);
        $this->assertEqualsCanonicalizing(['price_minor', 'name'], array_keys($audit->after));

        $count = $this->auditCount('service.updated', $this->org);
        $this->save($this->input(['price' => '300', 'name' => 'Individual therapy (50 min)']), $service);   // unchanged
        $this->assertSame($count, $this->auditCount('service.updated', $this->org));
    }

    #[Test]
    public function a_service_keeps_its_currency_when_the_organizations_currency_changes(): void
    {
        $old = $this->save($this->input(['name' => 'Old', 'price' => '100.00']));

        $this->actAs($this->admin, fn () => app(UpdateOrganizationProfile::class)($this->org, [
            'name' => $this->org->name, 'country_code' => 'GH', 'timezone' => 'Africa/Accra', 'currency' => 'USD', 'locale' => 'en',
        ]));

        $new = $this->save($this->input(['name' => 'New', 'price' => '100.00']));
        $this->save($this->input(['name' => 'Old', 'price' => '120.00']), $old);   // edited after the change

        $this->assertSame('USD', $this->row($new)->currency);
        $this->assertSame('GHS', $this->row($old)->currency);   // prices keep the currency they were set in
        $this->assertSame(12000, $this->row($old)->price_minor);
    }

    #[Test]
    public function links_to_people_and_places_that_are_no_longer_eligible_survive_an_edit(): void
    {
        $active = $this->addStaff($this->org, 'clinician');
        $leaver = $this->addStaff($this->org, 'clinician');
        $osu = $this->location($this->org);
        $shut = $this->location($this->org);

        $service = $this->save($this->input(['provider_ids' => [$active->id, $leaver->id], 'location_ids' => [$osu->id, $shut->id]]));

        $this->inTenant($this->org, function () use ($leaver, $shut) {
            $this->reload($leaver)->forceFill(['status' => MembershipStatus::Deactivated, 'deactivated_at' => now()])->save();
            $shut->forceFill(['is_active' => false])->save();
        });

        // The form can no longer offer them, so it submits only the others…
        $this->save($this->input(['provider_ids' => [$active->id], 'location_ids' => [$osu->id]]), $service);

        // …and the links to them are kept, so reactivating the clinician brings the service back with them.
        $this->assertEqualsCanonicalizing([$active->id, $leaver->id], $this->providerIds($service));
        $this->assertEqualsCanonicalizing([$osu->id, $shut->id], $this->locationIds($service));
        $this->assertSame(0, $this->auditCount('service.updated', $this->org));   // nothing changed, nothing audited

        // Removing an eligible one works, and is audited with names rather than ids.
        $this->save($this->input(['provider_ids' => [], 'location_ids' => [$osu->id]]), $service);
        $this->assertSame([$leaver->id], $this->providerIds($service));
        $audit = $this->lastAudit('service.updated', $this->org);
        $this->assertContains($active->user->name, $audit->before['providers']);
        $this->assertNotContains($active->user->name, $audit->after['providers']);
        $this->assertStringNotContainsString($active->id, json_encode($audit->toArray()));

        // An ineligible one cannot be added back by naming it.
        $this->assertRefused(fn () => $this->save($this->input(['provider_ids' => [$leaver->id]]), $service), 'invalid_provider', 'providers');
    }

    #[Test]
    public function service_names_are_unique_per_organization_ignoring_case(): void
    {
        $first = $this->save($this->input(['name' => 'Individual therapy']));
        $second = $this->save($this->input(['name' => 'Couples therapy']));

        $this->assertRefused(fn () => $this->save($this->input(['name' => 'INDIVIDUAL THERAPY'])), 'service_name_taken', 'name');
        $this->assertRefused(fn () => $this->save($this->input(['name' => 'individual therapy']), $second), 'service_name_taken', 'name');
        $this->save($this->input(['name' => 'individual THERAPY']), $first);   // its own name, differently cased

        $other = $this->createOrganization();
        $this->save($this->input(['name' => 'Individual therapy']), as: $other->ownerMembership);   // another organization: fine
        $this->assertSame(2, Service::query()->withoutGlobalScopes()->whereRaw("lower(name) = 'individual therapy'")->count());
    }

    #[Test]
    public function services_are_deactivated_not_deleted_and_the_change_is_audited_once(): void
    {
        $service = $this->save($this->input());

        $this->toggle($service, false);
        $this->toggle($service, false);   // a double submit
        $this->assertFalse($service->is_active);
        $this->assertSame(1, $this->auditCount('service.deactivated', $this->org));
        $this->assertSame(['is_active' => true], $this->lastAudit('service.deactivated', $this->org)->before);

        $this->toggle($service, true);
        $this->assertTrue($this->row($service)->is_active);
        $this->assertSame(1, $this->auditCount('service.activated', $this->org));
    }

    #[Test]
    public function only_someone_who_manages_services_can_change_them(): void
    {
        $clinician = $this->addStaff($this->org, 'clinician');
        $manager = $this->addStaff($this->org, 'practice_manager');
        $service = $this->save($this->input());

        $this->assertRefused(fn () => $this->save($this->input(['name' => 'Nope']), as: $clinician), 'forbidden');
        $this->assertRefused(fn () => $this->save($this->input(['price' => '1']), $service, $clinician), 'forbidden');
        $this->assertRefused(fn () => $this->toggle($service, false, $clinician), 'forbidden');

        $this->save($this->input(['price' => '260']), $service, $manager);   // allowed…

        $this->revokeFromRole($this->org, 'practice_manager', 'services.manage');   // …until the permission goes
        $this->assertRefused(fn () => $this->save($this->input(['price' => '270']), $service, $manager), 'forbidden');
        $this->assertRefused(fn () => $this->toggle($service, false, $manager), 'forbidden');
        $this->assertSame(26000, $this->row($service)->price_minor);
    }

    #[Test]
    public function another_organizations_service_is_not_found_and_left_untouched(): void
    {
        $other = $this->createOrganization();
        $foreign = $this->save($this->input(['name' => 'Theirs', 'price' => '99']), as: $other->ownerMembership);

        foreach ([
            fn () => $this->save($this->input(['name' => 'Hijacked', 'price' => '1']), $foreign),
            fn () => $this->toggle($foreign, false),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('An action reached a service of another organization.');
            } catch (ModelNotFoundException) {
                // expected
            }
        }

        $row = $this->row($foreign);
        $this->assertSame('Theirs', $row->name);
        $this->assertSame(9900, $row->price_minor);
        $this->assertTrue($row->is_active);
        $this->assertSame(0, $this->auditCount('service.updated') + $this->auditCount('service.deactivated'));
    }

    #[Test]
    public function the_database_would_refuse_cross_organization_links_even_if_the_application_did_not(): void
    {
        $other = $this->createOrganization();
        $foreignMember = $this->addStaff($other->organization, 'clinician');
        $service = $this->save($this->input());

        $this->expectException(QueryException::class);

        DB::table('service_providers')->insert(['organization_id' => $this->org->id, 'service_id' => $service->id, 'membership_id' => $foreignMember->id]);
    }
}
