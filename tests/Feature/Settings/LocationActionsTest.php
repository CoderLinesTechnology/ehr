<?php

namespace Tests\Feature\Settings;

use App\Domain\Organization\BusinessHours;
use App\Domain\Organization\ChangeLocationStatus;
use App\Domain\Organization\SaveLocation;
use App\Domain\Platform\CreatedOrganization;
use App\Domain\Shared\DomainException;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Settings\Concerns\WorksInsideOrganizations;
use Tests\TestCase;

class LocationActionsTest extends TestCase
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

    private function save(array $input, ?Location $location = null, ?OrganizationMembership $as = null): Location
    {
        return $this->actAs($as ?? $this->admin, fn () => app(SaveLocation::class)($input, $location));
    }

    private function toggle(Location $location, bool $active, ?OrganizationMembership $as = null): Location
    {
        return $this->actAs($as ?? $this->admin, fn () => app(ChangeLocationStatus::class)($location, $active));
    }

    private function row(Location $location): ?Location
    {
        return Location::query()->withoutGlobalScopes()->find($location->id);
    }

    #[Test]
    public function a_location_is_created_active_in_the_current_organization_with_normalised_details(): void
    {
        $hours = BusinessHours::fromForm([
            1 => ['closed' => '0', 'open' => '08:00', 'close' => '17:00'],
            2 => ['closed' => '0', 'open' => '08:00', 'close' => '17:00'],
            6 => ['closed' => '0', 'open' => '09:00', 'close' => '13:00'],
            7 => ['closed' => '1', 'open' => '09:00', 'close' => '13:00'],
        ]);

        $location = $this->save([
            'name' => "  Osu   Clinic \n",
            'address_line1' => ' 12 Oxford Street ', 'city' => 'Accra', 'country_code' => 'gh',
            'phone' => '+233 30 222 3333', 'email' => 'osu@example.com', 'business_hours' => $hours,
            'organization_id' => 'not-honoured', 'is_active' => false,   // neither can be set through the input
        ]);

        $row = $this->row($location);
        $this->assertSame($this->org->id, $row->organization_id);
        $this->assertTrue($row->is_active);
        $this->assertSame('Osu Clinic', $row->name);
        $this->assertSame('12 Oxford Street', $row->address_line1);
        $this->assertSame('GH', $row->country_code);
        $this->assertSame('Africa/Accra', $row->timezone);   // defaults to the organization's
        $this->assertNull($row->region);                     // blank → null

        // Stored as {"1": [["08:00","17:00"]], …} keyed by ISO weekday; closed days are absent.
        $stored = json_decode((string) DB::table('locations')->where('id', $location->id)->value('business_hours'), true);
        $this->assertSame(['1' => [['08:00', '17:00']], '2' => [['08:00', '17:00']], '6' => [['09:00', '13:00']]], $stored);
        $this->assertSame('Mon–Tue 08:00–17:00; Sat 09:00–13:00', BusinessHours::summary($row->business_hours));

        $audit = $this->lastAudit('location.created', $this->org);
        $this->assertSame('Osu Clinic', $audit->after['name']);
        $this->assertTrue($audit->after['is_active']);
        $this->assertSame($this->admin->user_id, $audit->actor_user_id);
        $this->assertSame('location', $audit->subject_type);
    }

    #[Test]
    public function a_location_can_have_its_own_timezone_and_no_published_hours(): void
    {
        $location = $this->save(['name' => 'Remote Office', 'timezone' => 'America/New_York', 'business_hours' => null]);

        $row = $this->row($location);
        $this->assertSame('America/New_York', $row->timezone);
        $this->assertNull($row->business_hours);
        $this->assertSame('Hours not set', BusinessHours::summary($row->business_hours));
    }

    public static function invalidLocations(): array
    {
        return [
            'no name' => [['name' => '   '], 'name'],
            'name too long' => [['name' => str_repeat('x', 121)], 'name'],
            'bad email' => [['name' => 'A', 'email' => 'not-an-email'], 'email'],
            'bad country' => [['name' => 'A', 'country_code' => 'ZZ'], 'country_code'],
            'bad timezone' => [['name' => 'A', 'timezone' => 'Mars/Olympus'], 'timezone'],
            'address too long' => [['name' => 'A', 'address_line1' => str_repeat('x', 201)], 'address_line1'],
            'hours not an array of days' => [['name' => 'A', 'business_hours' => 'always'], 'hours'],
            'unknown weekday' => [['name' => 'A', 'business_hours' => ['8' => [['08:00', '17:00']]]], 'hours'],
            'close before open' => [['name' => 'A', 'business_hours' => ['1' => [['17:00', '08:00']]]], 'hours'],
            'bad time' => [['name' => 'A', 'business_hours' => ['1' => [['8am', '5pm']]]], 'hours'],
            'overlapping windows' => [['name' => 'A', 'business_hours' => ['1' => [['08:00', '12:00'], ['11:00', '17:00']]]], 'hours'],
        ];
    }

    #[Test]
    #[DataProvider('invalidLocations')]
    public function malformed_locations_are_refused_and_nothing_is_stored(array $input, string $field): void
    {
        $this->assertRefused(fn () => $this->save($input), $this->codeFor($field), $field);

        $this->assertSame(0, Location::query()->withoutGlobalScopes()->where('organization_id', $this->org->id)->count());
        $this->assertSame(0, $this->auditCount('location.created', $this->org));
    }

    private function codeFor(string $field): string
    {
        return match ($field) {
            'name' => 'invalid_name',
            'email' => 'invalid_email',
            'country_code' => 'invalid_country',
            'timezone' => 'invalid_timezone',
            'hours' => 'invalid_hours',
            default => 'too_long',
        };
    }

    #[Test]
    public function business_hours_convert_between_the_form_and_storage(): void
    {
        $this->assertNull(BusinessHours::fromForm(null));
        $this->assertNull(BusinessHours::fromForm([1 => ['closed' => '1'], 2 => ['closed' => '0', 'open' => '', 'close' => '']]));   // all closed or blank → not published

        $stored = BusinessHours::fromForm([
            '1' => ['closed' => '0', 'open' => '08:30', 'close' => '17:00'],
            '3' => ['closed' => '0', 'open' => '10:00', 'close' => '14:00'],
        ]);
        $this->assertSame(['1' => [['08:30', '17:00']], '3' => [['10:00', '14:00']]], $stored);

        $form = BusinessHours::toForm($stored);
        $this->assertCount(7, $form);
        $this->assertFalse($form[1]['closed']);
        $this->assertSame('08:30', $form[1]['open']);
        $this->assertTrue($form[2]['closed']);
        $this->assertSame('14:00', $form[3]['close']);
        $this->assertSame($stored, BusinessHours::fromForm(array_map(fn (array $d) => ['closed' => $d['closed'] ? '1' : '0'] + $d, $form)));

        $this->assertTrue(BusinessHours::isValid(null));
        $this->assertTrue(BusinessHours::isValid(['1' => [['08:00', '12:00'], ['13:00', '17:00']]]));
        $this->assertFalse(BusinessHours::isValid(['1' => []]));
        $this->assertFalse(BusinessHours::isValid(['0' => [['08:00', '12:00']]]));
        $this->assertFalse(BusinessHours::isValid(['1' => [['08:00', '08:00']]]));
        $this->assertFalse(BusinessHours::isValid(['1' => [['24:00', '25:00']]]));
        $this->assertFalse(BusinessHours::isValid('x'));

        $this->expectException(DomainException::class);
        BusinessHours::fromForm(['1' => ['closed' => '0', 'open' => '17:00', 'close' => '08:00']]);
    }

    #[Test]
    public function editing_a_location_audits_exactly_what_changed(): void
    {
        $location = $this->save(['name' => 'Osu Clinic', 'city' => 'Accra', 'business_hours' => ['1' => [['08:00', '17:00']]]]);

        $this->save(['name' => 'Osu Clinic', 'city' => 'Tema', 'phone' => '+233 30 000 0000', 'business_hours' => ['1' => [['09:00', '17:00']]]], $location);

        $audit = $this->lastAudit('location.updated', $this->org);
        $this->assertEqualsCanonicalizing(['city', 'phone', 'business_hours'], array_keys($audit->after));
        $this->assertSame('Accra', $audit->before['city']);
        $this->assertSame('Tema', $audit->after['city']);
        $this->assertSame(['1' => [['08:00', '17:00']]], $audit->before['business_hours']);   // arrays, not JSON strings
        $this->assertSame(['1' => [['09:00', '17:00']]], $audit->after['business_hours']);
        $this->assertSame('Tema', $location->city);   // the caller's instance is current

        // Saving the same values again leaves no trace.
        $before = $this->auditCount('location.updated', $this->org);
        $this->save(['name' => 'Osu Clinic', 'city' => 'Tema', 'phone' => '+233 30 000 0000', 'business_hours' => ['1' => [['09:00', '17:00']]]], $location);
        $this->assertSame($before, $this->auditCount('location.updated', $this->org));
    }

    #[Test]
    public function editing_never_changes_whether_a_location_is_active(): void
    {
        $location = $this->save(['name' => 'Osu Clinic']);
        $this->toggle($location, false);

        $this->save(['name' => 'Osu Clinic Renamed', 'is_active' => true], $location);

        $this->assertFalse($this->row($location)->is_active);
    }

    #[Test]
    public function location_names_are_unique_per_organization_ignoring_case(): void
    {
        $osu = $this->save(['name' => 'Osu Clinic']);
        $legon = $this->save(['name' => 'Legon Centre']);

        $this->assertRefused(fn () => $this->save(['name' => 'OSU CLINIC']), 'location_name_taken', 'name');
        $this->assertRefused(fn () => $this->save(['name' => 'osu clinic'], $legon), 'location_name_taken', 'name');
        $this->save(['name' => 'OSU clinic'], $osu);   // its own name, differently cased: fine
        $this->assertSame('OSU clinic', $this->row($osu)->name);

        // Another organization may use the same name.
        $other = $this->createOrganization();
        $this->save(['name' => 'Osu Clinic'], as: $other->ownerMembership);
        $this->assertSame(2, Location::query()->withoutGlobalScopes()->whereRaw("lower(name) = 'osu clinic'")->count());
    }

    #[Test]
    public function the_location_limit_counts_active_locations_on_create_and_on_activate(): void
    {
        $starter = $this->createOrganization(plan: 'starter');   // max_locations = 1
        $admin = $starter->ownerMembership;

        $first = $this->save(['name' => 'Main'], as: $admin);
        $e = $this->assertRefused(fn () => $this->save(['name' => 'Second'], as: $admin), 'limit_reached');
        $this->assertStringContainsString('1 locations', $e->userMessage());
        $this->assertNull(Location::query()->withoutGlobalScopes()->where('name', 'Second')->first());

        // Deactivated locations do not count…
        $this->toggle($first, false, $admin);
        $second = $this->save(['name' => 'Second'], as: $admin);
        // …but bringing one back does.
        $this->assertRefused(fn () => $this->toggle($first, true, $admin), 'limit_reached');
        $this->assertFalse($this->row($first)->is_active);

        $this->toggle($second, false, $admin);
        $this->toggle($first, true, $admin);
        $this->assertTrue($this->row($first)->is_active);

        // Editing an active location never trips the limit.
        $this->save(['name' => 'Main Street'], $first, $admin);
        $this->assertSame('Main Street', $this->row($first)->name);
    }

    #[Test]
    public function activation_and_deactivation_are_audited_and_idempotent(): void
    {
        $location = $this->save(['name' => 'Osu Clinic']);

        $this->toggle($location, false);
        $this->toggle($location, false);   // a double submit
        $this->assertFalse($location->is_active);
        $this->assertSame(1, $this->auditCount('location.deactivated', $this->org));
        $this->assertSame(['is_active' => true], $this->lastAudit('location.deactivated', $this->org)->before);

        $this->toggle($location, true);
        $this->assertTrue($this->row($location)->is_active);
        $this->assertSame(1, $this->auditCount('location.activated', $this->org));

        // Locations are never deleted: the row is still there, history intact.
        $this->assertNotNull($this->row($location));
    }

    #[Test]
    public function only_someone_who_manages_locations_can_change_them(): void
    {
        $clinician = $this->addStaff($this->org, 'clinician');
        $manager = $this->addStaff($this->org, 'practice_manager');
        $location = $this->save(['name' => 'Osu Clinic']);

        $this->assertRefused(fn () => $this->save(['name' => 'Nope'], as: $clinician), 'forbidden');
        $this->assertRefused(fn () => $this->save(['name' => 'Renamed'], $location, $clinician), 'forbidden');
        $this->assertRefused(fn () => $this->toggle($location, false, $clinician), 'forbidden');

        $this->save(['name' => 'Renamed by manager'], $location, $manager);   // allowed…

        $this->revokeFromRole($this->org, 'practice_manager', 'locations.manage');   // …until the permission goes
        $this->assertRefused(fn () => $this->save(['name' => 'Again'], $location, $manager), 'forbidden');
        $this->assertRefused(fn () => $this->toggle($location, false, $manager), 'forbidden');
        $this->assertSame('Renamed by manager', $this->row($location)->name);
        $this->assertTrue($this->row($location)->is_active);
    }

    #[Test]
    public function another_organizations_location_is_not_found_and_left_untouched(): void
    {
        $other = $this->createOrganization();
        $foreign = $this->save(['name' => 'Theirs', 'city' => 'Kumasi'], as: $other->ownerMembership);

        foreach ([
            fn () => $this->save(['name' => 'Hijacked', 'city' => 'Accra'], $foreign),
            fn () => $this->toggle($foreign, false),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('An action reached a location of another organization.');
            } catch (ModelNotFoundException) {
                // expected
            }
        }

        $row = $this->row($foreign);
        $this->assertSame('Theirs', $row->name);
        $this->assertSame('Kumasi', $row->city);
        $this->assertTrue($row->is_active);
        $this->assertSame(0, $this->auditCount('location.updated') + $this->auditCount('location.deactivated'));
    }
}
