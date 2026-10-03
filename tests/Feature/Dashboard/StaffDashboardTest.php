<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Dashboard\StaffDashboard;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StaffDashboardTest extends TestCase
{
    private Organization $org;

    private OrganizationMembership $owner;

    private OrganizationMembership $clinician;

    private OrganizationMembership $other;

    private OrganizationMembership $receptionist;

    private OrganizationMembership $staff;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday 08:30 in Accra (UTC+0).
        CarbonImmutable::setTestNow('2026-03-02 08:30:00 UTC');

        $created = $this->createOrganization();
        $this->org = $created->organization;
        $this->owner = $created->ownerMembership;
        $this->clinician = $this->addStaff($this->org, 'clinician');
        $this->other = $this->addStaff($this->org, 'clinician');
        $this->receptionist = $this->addStaff($this->org, 'receptionist');
        $this->staff = $this->addStaff($this->org, 'staff');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function visit(OrganizationMembership $member, ?Organization $org = null)
    {
        $org ??= $this->org;

        return $this->actingAs($member->user->fresh())->get(route('app.dashboard', ['organization' => $org->slug]));
    }

    private function client(array $attributes = [], bool $demo = false, ?Organization $org = null): Client
    {
        return $this->inTenant($org ?? $this->org, fn () => ($demo ? Client::factory()->demo() : Client::factory())->create($attributes));
    }

    /** An appointment row starting at $when (UTC wall clock) with the given clinician. */
    private function appointment(Client $client, OrganizationMembership $clinician, string $when, string $status = 'confirmed', ?Organization $org = null, string $service = 'Therapy Session'): void
    {
        $org ??= $this->org;
        $this->inTenant($org, function () use ($org, $client, $clinician, $when, $status, $service) {
            $svc = Service::query()->where('name', $service)->first() ?? Service::factory()->create(['name' => $service]);
            $start = CarbonImmutable::parse($when, 'UTC');
            DB::table('appointments')->insert([
                'id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'record_environment' => $client->record_environment->value,
                'client_id' => $client->id, 'service_id' => $svc->id, 'clinician_membership_id' => $clinician->id,
                'starts_at' => $start->toIso8601String(), 'ends_at' => $start->addMinutes(50)->toIso8601String(),
                'timezone' => 'Africa/Accra', 'modality' => 'telehealth', 'status' => $status, 'price_minor' => 30000,
                'currency' => 'GHS', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function read(OrganizationMembership $member): array
    {
        return $this->inTenant($this->org, fn () => app(StaffDashboard::class)->for($member->user->fresh(), $member, $this->org), $member);
    }

    private function stat(array $data, string $key): ?array
    {
        return collect($data['stats'])->firstWhere('key', $key);
    }

    #[Test]
    public function it_renders_for_every_role_with_the_greeting_and_subtitle(): void
    {
        foreach ([$this->owner, $this->clinician, $this->receptionist, $this->staff] as $member) {
            $this->visit($member)->assertOk()
                ->assertSee('Good morning, '.explode(' ', $member->user->name)[0], false)
                ->assertSee("Here's a quick look at what's happening today.", false)
                ->assertDontSee('{{--', false);
        }
    }

    #[Test]
    public function the_greeting_follows_the_organizations_local_hour(): void
    {
        $this->assertSame('Good morning', StaffDashboard::greeting(9));
        $this->assertSame('Good afternoon', StaffDashboard::greeting(12));
        $this->assertSame('Good evening', StaffDashboard::greeting(18));

        // 19:30 UTC is 15:30 in New York in March (UTC-4 after DST) -> afternoon.
        $this->org->forceFill(['timezone' => 'America/New_York'])->save();
        CarbonImmutable::setTestNow('2026-03-02 19:30:00 UTC');
        $this->assertSame('Good afternoon', $this->read($this->owner)['greeting']);
    }

    #[Test]
    public function counts_exclude_demo_records_other_tenants_and_archived_clients_and_a_trend_needs_a_baseline(): void
    {
        $old = $this->client(); // live, created long ago
        DB::table('clients')->where('id', $old->id)->update(['created_at' => '2026-01-01 00:00:00']);
        $this->client(); // live, new
        $this->client(['status' => 'archived']);
        $this->client(demo: true);
        $foreign = $this->createOrganization();
        $this->client(org: $foreign->organization);

        $data = $this->read($this->owner);

        $this->assertSame(2, $this->stat($data, 'clients')['value']);
        $this->assertSame(100.0, $this->stat($data, 'clients')['trend']); // 1 -> 2
        $this->assertSame(1, $this->stat($data, 'new_clients')['value']);
        $this->assertNull($this->stat($data, 'new_clients')['trend']); // nothing in the previous 30 days
    }

    #[Test]
    public function upcoming_appointments_respect_view_versus_view_all(): void
    {
        $mine = $this->client(['primary_clinician_membership_id' => $this->clinician->id]);
        $theirs = $this->client(['primary_clinician_membership_id' => $this->other->id]);
        $this->appointment($mine, $this->clinician, '2026-03-02 10:00:00');
        $this->appointment($theirs, $this->other, '2026-03-02 11:30:00');
        $this->appointment($theirs, $this->other, '2026-03-03 10:00:00', 'scheduled');
        $this->appointment($theirs, $this->other, '2026-03-02 07:00:00'); // already started: not upcoming
        $this->appointment($theirs, $this->other, '2026-03-04 07:00:00', 'cancelled');

        $own = $this->read($this->clinician);
        $this->assertCount(1, $own['upcoming']);
        $this->assertSame('Today, 10:00 AM', $own['upcoming'][0]['when']);
        $this->assertSame('Confirmed', $own['upcoming'][0]['statusLabel']);
        $this->assertSame(1, $this->stat($own, 'upcoming')['value']);

        $all = $this->read($this->receptionist);
        $this->assertSame(['Today, 10:00 AM', 'Today, 11:30 AM', 'Tomorrow, 10:00 AM'], array_column($all['upcoming'], 'when'));
        $this->assertSame('Pending', $all['upcoming'][2]['statusLabel']);
        $this->assertSame(3, $this->stat($all, 'upcoming')['value']);
        $this->assertSame(3, $this->stat($all, 'today')['value']); // 07:00 (started), 10:00 and 11:30 today
        $this->assertSame(['7:00 AM', '10:00 AM', '11:30 AM'], array_column($all['today'], 'time'));
    }

    #[Test]
    public function the_page_lists_the_viewers_rows_only(): void
    {
        $mine = $this->client(['first_name' => 'Mine', 'last_name' => 'Client', 'primary_clinician_membership_id' => $this->clinician->id]);
        $theirs = $this->client(['first_name' => 'Theirs', 'last_name' => 'Client', 'primary_clinician_membership_id' => $this->other->id]);
        $this->appointment($mine, $this->clinician, '2026-03-02 10:00:00');
        $this->appointment($theirs, $this->other, '2026-03-02 11:00:00');

        $this->visit($this->clinician)->assertOk()->assertSee('Mine Client')->assertDontSee('Theirs Client');
        $this->visit($this->receptionist)->assertOk()->assertSee('Mine Client')->assertSee('Theirs Client');
    }

    #[Test]
    public function members_without_client_access_get_no_client_cards_or_recent_clients(): void
    {
        $client = $this->client();
        $this->appointment($client, $this->staff, '2026-03-02 10:00:00');

        $data = $this->read($this->staff);

        $this->assertNull($this->stat($data, 'clients'));
        $this->assertNotNull($this->stat($data, 'upcoming'));
        $this->assertSame([], $data['recentClients']);
    }

    #[Test]
    public function recent_clients_are_the_four_newest_visible_ones(): void
    {
        foreach (range(1, 6) as $i) {
            $c = $this->client(['first_name' => "Recent{$i}"]);
            DB::table('clients')->where('id', $c->id)->update(['created_at' => "2026-02-0{$i} 09:00:00"]);
        }

        $names = array_column($this->read($this->owner)['recentClients'], 'name');
        $this->assertCount(4, $names);
        $this->assertStringStartsWith('Recent6', $names[0]);
    }

    #[Test]
    public function quick_actions_follow_permissions_and_existing_routes(): void
    {
        $labels = fn (OrganizationMembership $m) => array_column($this->read($m)['quickActions'], 'label');

        $this->assertContains('Add New Client', $labels($this->owner));
        $this->assertContains('Add New Client', $labels($this->receptionist));
        $this->assertNotContains('Add New Client', $labels($this->staff));

        if (! Route::has('app.appointments.create')) {
            $this->assertNotContains('Schedule Appointment', $labels($this->owner));
        }
        foreach ([$this->owner, $this->staff] as $member) {
            foreach ($this->read($member)['quickActions'] as $action) {
                $this->assertStringContainsString('/o/'.$this->org->slug.'/', $action['url']);
            }
        }
    }

    #[Test]
    public function the_onboarding_checklist_shows_for_admins_only_while_incomplete(): void
    {
        $this->visit($this->owner)->assertOk()->assertSee('Finish setting up your practice');
        $this->visit($this->clinician)->assertOk()->assertDontSee('Finish setting up your practice');

        $this->org->forceFill(['onboarding_completed_at' => now()])->save();
        $this->visit($this->owner)->assertOk()->assertDontSee('Finish setting up your practice');
    }

    #[Test]
    public function the_query_count_is_bounded_and_does_not_grow_with_the_data(): void
    {
        $count = function (OrganizationMembership $member): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->read($member);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $small = $this->client();
        $this->appointment($small, $this->clinician, '2026-03-02 10:00:00');
        $count($this->owner); // warm memoised settings/entitlements/permissions
        $baseline = $count($this->owner);

        foreach (range(1, 12) as $i) {
            $c = $this->client();
            $this->appointment($c, $this->clinician, CarbonImmutable::parse('2026-03-03 10:00:00')->addDays($i)->toDateTimeString(), 'confirmed');
        }
        $grown = $count($this->owner);

        $this->assertSame($baseline, $grown, 'dashboard queries must not depend on the number of rows');
        $this->assertLessThanOrEqual(16, $grown);
        $this->assertLessThanOrEqual(10, $count($this->clinician));
    }
}
