<?php

namespace Tests\Feature\Scheduling;

use App\Domain\Identity\MembershipStatus;
use App\Domain\Scheduling\AvailabilityModality;
use App\Domain\Scheduling\AvailabilityRuleData;
use App\Domain\Scheduling\BlockedTimeData;
use App\Domain\Scheduling\BlockedTimeKind;
use App\Domain\Scheduling\CreateBlockedTime;
use App\Domain\Scheduling\DeleteAvailabilityRule;
use App\Domain\Scheduling\DeleteBlockedTime;
use App\Domain\Scheduling\SaveAvailabilityRule;
use App\Domain\Shared\DomainException;
use App\Domain\Tenancy\TenantMismatch;
use App\Models\AuditLog;
use App\Models\AvailabilityRule;
use App\Models\BlockedTime;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class AvailabilityManagementTest extends SchedulingTestCase
{
    private OrganizationMembership $clinician;

    private Location $location;

    private Service $therapy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinician = $this->clinician();
        $this->location = $this->location();
        $this->therapy = $this->service(['duration_minutes' => 60], [$this->clinician]);
    }

    private function data(array $overrides = []): AvailabilityRuleData
    {
        return new AvailabilityRuleData(...array_merge([
            'membership' => $this->clinician,
            'weekday' => 1,
            'startTime' => '09:00',
            'endTime' => '17:00',
            'modality' => AvailabilityModality::InPerson,
            'effectiveFrom' => '2026-10-01',
            'location' => $this->location,
        ], $overrides));
    }

    private function save(AvailabilityRuleData $data, ?AvailabilityRule $rule = null): AvailabilityRule
    {
        return app(SaveAvailabilityRule::class)($data, $rule);
    }

    #[Test]
    public function a_rule_is_created_with_its_service_restriction_and_audited(): void
    {
        $rule = $this->save($this->data(['serviceIds' => [$this->therapy->id], 'repeatEveryWeeks' => 2, 'effectiveUntil' => '2026-12-31']));

        $fresh = $rule->fresh();
        $this->assertSame([1, '09:00:00', '17:00:00', AvailabilityModality::InPerson, 2, '2026-10-01', '2026-12-31'],
            [$fresh->weekday, $fresh->start_time, $fresh->end_time, $fresh->modality, $fresh->repeat_every_weeks, $fresh->effective_from, $fresh->effective_until]);
        $this->assertSame([$this->therapy->id], DB::table('availability_rule_services')->where('availability_rule_id', $rule->id)->pluck('service_id')->all());

        $audit = AuditLog::query()->where('action', 'availability.rule_saved')->sole();
        $this->assertNull($audit->before);
        $this->assertSame([$this->therapy->id], $audit->after['service_ids']);
        $this->assertSame('Availability added: Monday 09:00–17:00', $audit->summary);
    }

    #[Test]
    public function an_update_replaces_the_restriction_and_audits_before_and_after(): void
    {
        $assessment = $this->service(['duration_minutes' => 60], [$this->clinician]);
        $rule = $this->save($this->data(['serviceIds' => [$this->therapy->id]]));

        $this->save($this->data(['weekday' => 3, 'endTime' => '12:00', 'serviceIds' => [$assessment->id]]), $rule);

        $this->assertSame([3, '12:00:00'], [$rule->fresh()->weekday, $rule->fresh()->end_time]);
        $this->assertSame([$assessment->id], DB::table('availability_rule_services')->where('availability_rule_id', $rule->id)->pluck('service_id')->all());
        $audit = AuditLog::query()->where('action', 'availability.rule_saved')->orderByDesc('occurred_at')->orderByDesc('id')->first();
        $this->assertSame([1, [$this->therapy->id]], [$audit->before['weekday'], $audit->before['service_ids']]);
        $this->assertSame([3, [$assessment->id]], [$audit->after['weekday'], $audit->after['service_ids']]);
    }

    #[Test]
    public function a_telehealth_rule_may_have_no_location_and_a_window_may_end_at_midnight(): void
    {
        $rule = $this->save($this->data(['modality' => AvailabilityModality::Telehealth, 'location' => null, 'startTime' => '22:00', 'endTime' => '24:00']));

        $this->assertNull($rule->fresh()->location_id);
        $service = $this->service(['duration_minutes' => 60, 'allows_telehealth' => true], [$this->clinician]);
        $this->setting('scheduling.slot_interval_minutes', '60');
        $this->assertSame(['2026-10-05 22:00', '2026-10-05 23:00'], $this->startsUtc($this->slots($service, '2026-10-05')));
    }

    /** @return iterable<string, array{string, string, Closure}> */
    public static function invalidRules(): iterable
    {
        yield 'suspended clinician' => ['clinician_unavailable', 'membership_id', function (self $t) {
            $t->clinician->forceFill(['status' => MembershipStatus::Suspended])->save();

            return [];
        }];
        yield 'weekday 0' => ['invalid_weekday', 'weekday', fn () => ['weekday' => 0]];
        yield 'weekday 8' => ['invalid_weekday', 'weekday', fn () => ['weekday' => 8]];
        yield 'malformed start' => ['invalid_time', 'start_time', fn () => ['startTime' => '9am']];
        yield 'start at 24:00' => ['invalid_time', 'start_time', fn () => ['startTime' => '24:00']];
        yield 'malformed end' => ['invalid_time', 'end_time', fn () => ['endTime' => '17:60']];
        yield 'end before start' => ['invalid_window', 'end_time', fn () => ['startTime' => '17:00', 'endTime' => '09:00']];
        yield 'empty window' => ['invalid_window', 'end_time', fn () => ['startTime' => '09:00', 'endTime' => '09:00']];
        yield 'repeat 0' => ['invalid_repeat', 'repeat_every_weeks', fn () => ['repeatEveryWeeks' => 0]];
        yield 'repeat 9' => ['invalid_repeat', 'repeat_every_weeks', fn () => ['repeatEveryWeeks' => 9]];
        yield 'bad start date' => ['invalid_date', 'effective_from', fn () => ['effectiveFrom' => '2026-02-30']];
        yield 'bad end date' => ['invalid_date', 'effective_until', fn () => ['effectiveUntil' => '31/12/2026']];
        yield 'end date before start date' => ['invalid_range', 'effective_until', fn () => ['effectiveUntil' => '2026-09-30']];
        yield 'in person without location' => ['location_required', 'location_id', fn () => ['location' => null]];
        yield 'any modality without location' => ['location_required', 'location_id', fn () => ['location' => null, 'modality' => AvailabilityModality::Any]];
        yield 'closed location' => ['location_inactive', 'location_id', fn (self $t) => ['location' => $t->location('Africa/Accra', ['is_active' => false])]];
        yield 'service the clinician does not provide' => ['services_not_provided', 'service_ids', fn (self $t) => ['serviceIds' => [$t->service()->id]]];
        yield 'not a service id' => ['services_not_provided', 'service_ids', fn () => ['serviceIds' => ['not-a-uuid']]];
        yield 'service of another organization' => ['services_not_provided', 'service_ids', function (self $t) {
            $other = $t->createOrganization()->organization;

            return ['serviceIds' => [$t->inTenant($other, fn () => Service::factory()->create())->id]];
        }];
    }

    #[Test]
    #[DataProvider('invalidRules')]
    public function invalid_rules_are_refused(string $code, string $field, Closure $arrange): void
    {
        $overrides = $arrange($this);

        try {
            $this->save($this->data($overrides));
            $this->fail("Expected {$code}.");
        } catch (DomainException $e) {
            $this->assertSame([$code, $field], [$e->errorCode(), $e->field()], $e->userMessage());
        }

        $this->assertSame(0, AvailabilityRule::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'availability.rule_saved')->count());
    }

    #[Test]
    public function a_clinician_or_location_of_another_organization_is_refused(): void
    {
        $other = $this->createOrganization()->organization;
        [$foreignClinician, $foreignLocation] = $this->inTenant($other, fn () => [$this->addStaff($other), Location::factory()->create()]);

        foreach ([['membership' => $foreignClinician], ['location' => $foreignLocation]] as $overrides) {
            try {
                $this->save($this->data($overrides));
                $this->fail('A cross-tenant rule must be refused.');
            } catch (TenantMismatch) {
                // expected
            }
        }

        $this->assertSame(0, AvailabilityRule::acrossTenants()->count());
    }

    #[Test]
    public function deleting_a_rule_removes_it_and_its_restriction_and_keeps_an_audit_record(): void
    {
        $rule = $this->save($this->data(['serviceIds' => [$this->therapy->id]]));

        app(DeleteAvailabilityRule::class)($rule);

        $this->assertNull(AvailabilityRule::query()->find($rule->id));
        $this->assertSame(0, DB::table('availability_rule_services')->where('availability_rule_id', $rule->id)->count());
        $audit = AuditLog::query()->where('action', 'availability.rule_deleted')->sole();
        $this->assertSame([$rule->id, 1, [$this->therapy->id]], [$audit->subject_id, $audit->before['weekday'], $audit->before['service_ids']]);
    }

    #[Test]
    public function a_timed_block_is_stored_as_given_and_audited(): void
    {
        $block = app(CreateBlockedTime::class)(BlockedTimeData::timed(
            BlockedTimeKind::Blocked,
            CarbonImmutable::parse('2026-10-06 10:00', 'Africa/Lagos'), // 09:00 UTC
            CarbonImmutable::parse('2026-10-06 12:00', 'Africa/Lagos'),
            membership: $this->clinician,
            title: ' Supervision ',
            actor: $this->actor,
        ));

        $fresh = $block->fresh();
        $this->assertSame(['2026-10-06 09:00', '2026-10-06 11:00'], [$fresh->starts_at->utc()->format('Y-m-d H:i'), $fresh->ends_at->utc()->format('Y-m-d H:i')]);
        $this->assertSame([$this->clinician->id, null, 'Supervision', false, $this->actor->id],
            [$fresh->membership_id, $fresh->location_id, $fresh->title, $fresh->all_day, $fresh->created_by_user_id]);
        $this->assertSame('blocked', AuditLog::query()->where('action', 'availability.blocked_time_created')->sole()->after['kind']);
    }

    #[Test]
    public function an_all_day_block_runs_from_local_midnight_to_the_next_local_midnight_across_dst(): void
    {
        $newYork = $this->location('America/New_York');

        $springForward = app(CreateBlockedTime::class)(BlockedTimeData::allDay(BlockedTimeKind::Holiday, '2026-03-08', location: $newYork));
        $this->assertSame(['2026-03-08 05:00', '2026-03-09 04:00'], [
            $springForward->fresh()->starts_at->utc()->format('Y-m-d H:i'), $springForward->fresh()->ends_at->utc()->format('Y-m-d H:i'),
        ]);
        $this->assertSame(23.0, $springForward->fresh()->starts_at->diffInHours($springForward->fresh()->ends_at));
        $this->assertTrue($springForward->fresh()->all_day);

        // Without a location: the organization's timezone (Accra, UTC+0); end date inclusive.
        $christmas = app(CreateBlockedTime::class)(BlockedTimeData::allDay(BlockedTimeKind::Holiday, '2026-12-24', '2026-12-26'));
        $this->assertSame(['2026-12-24 00:00', '2026-12-27 00:00'], [
            $christmas->fresh()->starts_at->utc()->format('Y-m-d H:i'), $christmas->fresh()->ends_at->utc()->format('Y-m-d H:i'),
        ]);
    }

    #[Test]
    public function slot_finding_honours_a_new_block_and_its_removal(): void
    {
        $this->setting('scheduling.slot_interval_minutes', '60');
        $this->rule($this->clinician, $this->location, 1, '09:00', '12:00');
        $block = app(CreateBlockedTime::class)(BlockedTimeData::allDay(BlockedTimeKind::Leave, '2026-10-05', membership: $this->clinician));

        $this->assertTrue($this->slots($this->therapy, '2026-10-05')->isEmpty());

        app(DeleteBlockedTime::class)($block);

        $this->assertCount(3, $this->slots($this->therapy, '2026-10-05'));
        $this->assertNull(BlockedTime::query()->find($block->id));
        $this->assertSame('leave', AuditLog::query()->where('action', 'availability.blocked_time_deleted')->sole()->before['kind']);
    }

    /** @return iterable<string, array{string, Closure}> */
    public static function invalidBlocks(): iterable
    {
        yield 'end before start' => ['invalid_range', fn () => BlockedTimeData::timed(BlockedTimeKind::Blocked, CarbonImmutable::parse('2026-10-06 12:00'), CarbonImmutable::parse('2026-10-06 11:00'))];
        yield 'zero length' => ['invalid_range', fn () => BlockedTimeData::timed(BlockedTimeKind::Blocked, CarbonImmutable::parse('2026-10-06 12:00'), CarbonImmutable::parse('2026-10-06 12:00'))];
        yield 'bad date' => ['invalid_date', fn () => BlockedTimeData::allDay(BlockedTimeKind::Leave, '2026-13-01')];
        yield 'last day before first' => ['invalid_range', fn () => BlockedTimeData::allDay(BlockedTimeKind::Leave, '2026-10-06', '2026-10-05')];
        yield 'over a year' => ['range_too_long', fn () => BlockedTimeData::allDay(BlockedTimeKind::Leave, '2026-10-06', '2027-10-07')];
        yield 'title too long' => ['too_long', fn () => BlockedTimeData::allDay(BlockedTimeKind::Leave, '2026-10-06', title: str_repeat('x', 121))];
    }

    #[Test]
    #[DataProvider('invalidBlocks')]
    public function invalid_blocks_are_refused(string $code, Closure $data): void
    {
        try {
            app(CreateBlockedTime::class)($data());
            $this->fail("Expected {$code}.");
        } catch (DomainException $e) {
            $this->assertSame($code, $e->errorCode(), $e->userMessage());
        }

        $this->assertSame(0, BlockedTime::query()->count());
    }
}
