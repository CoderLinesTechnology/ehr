<?php

namespace Tests\Feature\Scheduling;

use App\Domain\Identity\MembershipStatus;
use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\AvailabilityModality;
use App\Domain\Scheduling\BlockedTimeKind;
use App\Domain\Scheduling\Modality;
use App\Domain\Scheduling\SlotQuery;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\DomainException;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use Carbon\CarbonImmutable;
use Database\Factories\BlockedTimeFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class SlotFinderTest extends SchedulingTestCase
{
    // 2026-10-05 is a Monday; the clock is frozen at Friday 2026-10-02 08:00 UTC.

    #[Test]
    public function it_offers_slots_inside_a_rule_window_on_its_weekday_only(): void
    {
        $clinician = $this->clinician();
        $location = $this->location();
        $service = $this->service(['duration_minutes' => 60], [$clinician]);
        $this->rule($clinician, $location, 1, '09:00', '12:00');

        $result = $this->slots($service, '2026-10-04', '2026-10-10');

        $this->assertSame([
            '2026-10-05 09:00', '2026-10-05 09:15', '2026-10-05 09:30', '2026-10-05 09:45', '2026-10-05 10:00',
            '2026-10-05 10:15', '2026-10-05 10:30', '2026-10-05 10:45', '2026-10-05 11:00',
        ], $this->startsUtc($result));

        $slot = $result->first();
        $this->assertSame('2026-10-05 10:00', $slot->endsAt->utc()->format('Y-m-d H:i'));
        $this->assertSame('UTC', $slot->startsAt->getTimezone()->getName());
        $this->assertSame($clinician->id, $slot->clinicianMembershipId);
        $this->assertSame($location->id, $slot->locationId);
        $this->assertSame(Modality::InPerson, $slot->modality);
        $this->assertSame('Africa/Accra', $slot->timezone);
    }

    #[Test]
    public function starts_are_aligned_to_the_organization_slot_interval_in_local_time(): void
    {
        $this->setting('scheduling.slot_interval_minutes', '30');
        $clinician = $this->clinician();
        $location = $this->location();
        $service = $this->service(['duration_minutes' => 30], [$clinician]);
        $this->rule($clinician, $location, 1, '09:10', '11:00');

        $this->assertSame(['2026-10-05 09:30', '2026-10-05 10:00', '2026-10-05 10:30'], $this->startsUtc($this->slots($service, '2026-10-05')));

        // An appointment ending off-grid (10:05) pushes the next start to the next aligned time.
        Appointment::factory()->for($clinician, 'clinician')->for($location)->at(CarbonImmutable::parse('2026-10-05 09:30', 'UTC'))
            ->create(['ends_at' => CarbonImmutable::parse('2026-10-05 10:05', 'UTC')]);

        $this->assertSame(['2026-10-05 10:30'], $this->startsUtc($this->slots($service, '2026-10-05')));
    }

    #[Test]
    public function every_slot_leaves_room_for_the_whole_service_duration(): void
    {
        $this->setting('scheduling.slot_interval_minutes', '30');
        $clinician = $this->clinician();
        $location = $this->location();
        $service = $this->service(['duration_minutes' => 90], [$clinician]);
        $this->rule($clinician, $location, 1, '09:00', '12:00');

        $this->assertSame(
            ['2026-10-05 09:00', '2026-10-05 09:30', '2026-10-05 10:00', '2026-10-05 10:30'],
            $this->startsUtc($this->slots($service, '2026-10-05')),
        );

        // A window shorter than the service offers nothing.
        $short = $this->service(['duration_minutes' => 240], [$clinician]);
        $this->assertTrue($this->slots($short, '2026-10-05')->isEmpty());
    }

    #[Test]
    public function repeat_every_weeks_counts_weeks_from_the_week_of_effective_from(): void
    {
        $this->setting('scheduling.slot_interval_minutes', '60');
        $clinician = $this->clinician();
        $location = $this->location();
        $service = $this->service(['duration_minutes' => 60], [$clinician]);
        // Effective from Wednesday 30 Sep: that ISO week (Mon 28 Sep) is week 0,
        // so Thursday 1 Oct is the first occurrence, then every other Thursday.
        $this->rule($clinician, $location, 4, '09:00', '10:00', ['repeat_every_weeks' => 2, 'effective_from' => '2026-09-30']);

        $starts = array_merge(
            $this->startsUtc($this->slots($service, '2026-09-21', '2026-10-17')),
            $this->startsUtc($this->slots($service, '2026-10-18', '2026-10-31')),
        );

        // Not Thu 24 Sep (before effective_from), not the odd weeks (8 and 22 Oct).
        $this->assertSame(['2026-10-01 09:00', '2026-10-15 09:00', '2026-10-29 09:00'], $starts);
    }

    #[Test]
    public function effective_dates_are_inclusive_business_dates(): void
    {
        $this->setting('scheduling.slot_interval_minutes', '60');
        $clinician = $this->clinician();
        $location = $this->location();
        $service = $this->service(['duration_minutes' => 60], [$clinician]);
        $this->rule($clinician, $location, 3, '09:00', '10:00', ['effective_from' => '2026-10-07', 'effective_until' => '2026-10-21']);

        $this->assertSame(
            ['2026-10-07 09:00', '2026-10-14 09:00', '2026-10-21 09:00'],
            $this->startsUtc($this->slots($service, '2026-09-28', '2026-10-28')),
        );
    }

    #[Test]
    public function a_rule_restricted_to_services_offers_only_those_services(): void
    {
        $this->setting('scheduling.slot_interval_minutes', '60');
        $clinician = $this->clinician();
        $location = $this->location();
        $therapy = $this->service(['duration_minutes' => 60], [$clinician]);
        $assessment = $this->service(['duration_minutes' => 60], [$clinician]);
        AvailabilityRule::factory()->restrictedTo($therapy)->create([
            'membership_id' => $clinician->id, 'location_id' => $location->id, 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '10:00',
        ]);
        $this->rule($clinician, $location, 2, '09:00', '10:00'); // open to every service

        $this->assertSame(['2026-10-05 09:00', '2026-10-06 09:00'], $this->startsUtc($this->slots($therapy, '2026-10-05', '2026-10-06')));
        $this->assertSame(['2026-10-06 09:00'], $this->startsUtc($this->slots($assessment, '2026-10-05', '2026-10-06')));
    }

    #[Test]
    public function a_telehealth_rule_without_a_location_uses_the_organization_timezone(): void
    {
        $this->useOrganization('Asia/Tokyo');
        $this->setting('scheduling.slot_interval_minutes', '60');
        $clinician = $this->clinician();
        $service = $this->service(['duration_minutes' => 60, 'allows_in_person' => false, 'allows_telehealth' => true], [$clinician]);
        $this->rule($clinician, null, 1, '09:00', '11:00');

        $result = $this->slots($service, '2026-10-05');

        // 09:00 in Tokyo (UTC+9) is midnight UTC.
        $this->assertSame(['2026-10-05 00:00', '2026-10-05 01:00'], $this->startsUtc($result));
        $this->assertSame(['2026-10-05 09:00', '2026-10-05 10:00'], $this->startsLocal($result));
        $this->assertNull($result->first()->locationId);
        $this->assertSame(Modality::Telehealth, $result->first()->modality);
        $this->assertSame('Asia/Tokyo', $result->first()->timezone);
    }

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function dstDays(): iterable
    {
        yield 'Sunday before spring forward (EST, UTC-5)' => ['2026-03-01', 'EST', ['14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00', '21:00']];
        yield 'spring forward day (EDT, UTC-4)' => ['2026-03-08', 'EDT', ['13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00']];
        yield 'Sunday before fall back (EDT, UTC-4)' => ['2026-10-25', 'EDT', ['13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00']];
        yield 'fall back day (EST, UTC-5)' => ['2026-11-01', 'EST', ['14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00', '21:00']];
    }

    #[Test]
    #[DataProvider('dstDays')]
    public function business_hours_map_to_the_right_utc_instants_across_dst(string $date, string $abbreviation, array $expectedUtc): void
    {
        $this->setting('scheduling.slot_interval_minutes', '60');
        $clinician = $this->clinician();
        $newYork = $this->location('America/New_York');
        $service = $this->service(['duration_minutes' => 60], [$clinician]);
        $this->rule($clinician, $newYork, 7, '09:00', '17:00');

        $result = $this->slots($service, $date);

        $this->assertSame(array_map(fn ($t) => "{$date} {$t}", $expectedUtc), $this->startsUtc($result));
        $this->assertSame(
            array_map(fn ($h) => sprintf('%s %02d:00', $date, $h), range(9, 16)),
            $this->startsLocal($result),
            'Every local hour 09:00–16:00 is offered exactly once.',
        );
        $this->assertSame($abbreviation, $result->first()->localStart()->format('T'));
        $this->assertSame('America/New_York', $result->first()->timezone);
    }

    #[Test]
    public function a_window_spanning_the_dst_change_has_no_phantom_or_missing_slots(): void
    {
        $this->setting('scheduling.slot_interval_minutes', '60');
        $clinician = $this->clinician();
        $newYork = $this->location('America/New_York');
        $service = $this->service(['duration_minutes' => 60], [$clinician]);
        $this->rule($clinician, $newYork, 7, '00:00', '06:00');

        // Spring forward: 02:00 does not exist, the night is an hour shorter.
        $spring = $this->slots($service, '2026-03-08');
        $this->assertSame(['00:00 EST', '01:00 EST', '03:00 EDT', '04:00 EDT', '05:00 EDT'],
            array_map(fn ($s) => $s->localStart()->format('H:i T'), $spring->slots));
        $this->assertSame(['05:00', '06:00', '07:00', '08:00', '09:00'],
            array_map(fn ($s) => $s->startsAt->format('H:i'), $spring->slots));

        // Fall back: 01:00 happens twice — both hours are real and bookable.
        $fall = $this->slots($service, '2026-11-01');
        $this->assertSame(['00:00 EDT', '01:00 EDT', '01:00 EST', '02:00 EST', '03:00 EST', '04:00 EST', '05:00 EST'],
            array_map(fn ($s) => $s->localStart()->format('H:i T'), $fall->slots));
        $this->assertSame(['04:00', '05:00', '06:00', '07:00', '08:00', '09:00', '10:00'],
            array_map(fn ($s) => $s->startsAt->format('H:i'), $fall->slots));
    }

    #[Test]
    public function blocked_time_applies_to_everyone_one_clinician_or_one_location(): void
    {
        $this->setting('scheduling.slot_interval_minutes', '60');
        $ama = $this->clinician();
        $kofi = $this->clinician();
        $osu = $this->location();
        $labone = $this->location();
        $service = $this->service(['duration_minutes' => 60, 'allows_telehealth' => true], [$ama, $kofi]);
        foreach ([[$ama, $osu, 1], [$kofi, $osu, 1], [$ama, $labone, 2], [$kofi, $labone, 2]] as [$clinician, $location, $weekday]) {
            $this->rule($clinician, $location, $weekday, '09:00', '12:00', ['modality' => AvailabilityModality::Any]);
        }

        $at = fn (string $start, string $end) => [CarbonImmutable::parse($start, 'UTC'), CarbonImmutable::parse($end, 'UTC')];

        // Everyone, everywhere: Monday 09:00–10:00.
        BlockedTimeFactory::new()->between(...$at('2026-10-05 09:00', '2026-10-05 10:00'))->create(['kind' => BlockedTimeKind::Holiday]);
        // Only Ama: Monday 10:00–11:00.
        BlockedTimeFactory::new()->between(...$at('2026-10-05 10:00', '2026-10-05 11:00'))->create(['membership_id' => $ama->id, 'kind' => BlockedTimeKind::Leave]);
        // Only the Labone location (everyone): Tuesday 09:00–12:00.
        BlockedTimeFactory::new()->between(...$at('2026-10-06 09:00', '2026-10-06 12:00'))->create(['location_id' => $labone->id]);

        $monday = $this->slots($service, '2026-10-05', modality: Modality::InPerson);
        $this->assertSame(['2026-10-05 11:00'], $this->startsUtc($this->slots($service, '2026-10-05', clinician: $ama, modality: Modality::InPerson)));
        $this->assertSame(['2026-10-05 10:00', '2026-10-05 11:00'], $this->startsUtc($this->slots($service, '2026-10-05', clinician: $kofi, modality: Modality::InPerson)));
        $this->assertCount(3, $monday);

        // The location block removes in-person time at Labone only; telehealth from there stays.
        $this->assertTrue($this->slots($service, '2026-10-06', modality: Modality::InPerson)->isEmpty());
        $this->assertCount(6, $this->slots($service, '2026-10-06', modality: Modality::Telehealth));
    }

    #[Test]
    public function occupying_appointments_take_the_clinicians_time_at_every_location_and_cancelled_ones_free_it(): void
    {
        $this->setting('scheduling.slot_interval_minutes', '60');
        $clinician = $this->clinician();
        $osu = $this->location();
        $labone = $this->location();
        $service = $this->service(['duration_minutes' => 60], [$clinician]);
        $this->rule($clinician, $osu, 1, '09:00', '15:00');
        $book = fn (string $start) => Appointment::factory()->for($clinician, 'clinician')->for($service)
            ->at(CarbonImmutable::parse("2026-10-05 {$start}", 'UTC'));

        $book('09:00')->for($osu)->create();
        $book('10:00')->for($labone)->status(AppointmentStatus::Confirmed)->create(); // other location still busy
        $book('11:00')->for($osu)->create(['allow_overlap' => true]); // a knowing overbook still occupies
        $book('12:00')->for($osu)->cancelled()->create();
        $book('13:00')->for($osu)->status(AppointmentStatus::NoShow)->create();
        $book('14:00')->for($osu)->status(AppointmentStatus::Rescheduled)->create();

        $this->assertSame(
            ['2026-10-05 12:00', '2026-10-05 13:00', '2026-10-05 14:00'],
            $this->startsUtc($this->slots($service, '2026-10-05')),
        );
    }

    #[Test]
    public function an_appointment_that_started_the_day_before_still_takes_the_early_morning(): void
    {
        $this->setting('scheduling.slot_interval_minutes', '60');
        $clinician = $this->clinician();
        $location = $this->location();
        $service = $this->service(['duration_minutes' => 60], [$clinician]);
        $this->rule($clinician, $location, 1, '00:00', '03:00');
        Appointment::factory()->for($clinician, 'clinician')->for($location)->at(CarbonImmutable::parse('2026-10-04 23:00', 'UTC'))
            ->create(['ends_at' => CarbonImmutable::parse('2026-10-05 01:30', 'UTC')]);

        $this->assertSame(['2026-10-05 02:00'], $this->startsUtc($this->slots($service, '2026-10-05')));
    }

    #[Test]
    public function minimum_notice_and_maximum_advance_bind_online_booking_only(): void
    {
        $this->setting('scheduling.slot_interval_minutes', '60');
        $this->setting('scheduling.min_notice_hours', 24);
        $this->setting('scheduling.max_advance_days', 7);
        $clinician = $this->clinician();
        $location = $this->location();
        $service = $this->service(['duration_minutes' => 60], [$clinician]);
        foreach (range(1, 7) as $weekday) {
            $this->rule($clinician, $location, $weekday, '09:00', '12:00');
        }

        // Now: Fri 2 Oct 08:00 UTC. Online: from Sat 3 Oct 08:00 to Fri 9 Oct 08:00.
        $online = $this->slots($service, '2026-10-01', '2026-10-31', online: true);
        $this->assertSame('2026-10-03 09:00', $this->startsUtc($online)[0]);
        $this->assertSame('2026-10-08 11:00', $this->startsUtc($online)[count($online) - 1]);
        $this->assertCount(6 * 3, $online);

        // Staff are not bound by either.
        $staff = $this->slots($service, '2026-10-01', '2026-10-31');
        $this->assertSame('2026-10-01 09:00', $this->startsUtc($staff)[0]);
        $this->assertSame('2026-10-31 11:00', $this->startsUtc($staff)[count($staff) - 1]);
        $this->assertCount(31 * 3, $staff);
    }

    #[Test]
    public function minimum_notice_inside_a_day_aligns_the_first_slot_up(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 07:07:30', 'UTC'));
        $this->setting('scheduling.min_notice_hours', 2);
        $clinician = $this->clinician();
        $location = $this->location();
        $service = $this->service(['duration_minutes' => 30], [$clinician]);
        $this->rule($clinician, $location, 1, '08:00', '10:00');

        $this->assertSame(['2026-10-05 09:15', '2026-10-05 09:30'], $this->startsUtc($this->slots($service, '2026-10-05', online: true)));
    }

    /** @return iterable<string, array{string}> */
    public static function unbookable(): iterable
    {
        yield 'inactive rule' => ['rule'];
        yield 'inactive service' => ['service'];
        yield 'suspended membership' => ['membership'];
        yield 'non-provider membership' => ['provider'];
        yield 'closed location' => ['location'];
        yield 'clinician no longer provides the service' => ['provides'];
    }

    #[Test]
    #[DataProvider('unbookable')]
    public function inactive_rules_services_memberships_and_locations_offer_nothing(string $what): void
    {
        $clinician = $this->clinician();
        $location = $this->location();
        $service = $this->service(['duration_minutes' => 60], [$clinician]);
        $rule = $this->rule($clinician, $location, 1, '09:00', '12:00');
        $this->assertFalse($this->slots($service, '2026-10-05')->isEmpty());

        match ($what) {
            'rule' => $rule->forceFill(['is_active' => false])->save(),
            'service' => $service->forceFill(['is_active' => false])->save(),
            'membership' => $clinician->forceFill(['status' => MembershipStatus::Suspended])->save(),
            'provider' => $clinician->forceFill(['is_provider' => false])->save(),
            'location' => $location->forceFill(['is_active' => false])->save(),
            'provides' => $service->providers()->detach($clinician->id),
        };

        $this->assertTrue($this->slots($service->fresh(), '2026-10-05')->isEmpty());
        $this->assertTrue($this->slots($service->fresh(), '2026-10-05', clinician: $clinician->fresh())->isEmpty());
    }

    #[Test]
    public function online_booking_needs_the_rule_and_the_service_to_be_bookable_online(): void
    {
        $this->setting('scheduling.min_notice_hours', 0);
        $clinician = $this->clinician();
        $location = $this->location();
        $service = $this->service(['duration_minutes' => 60, 'is_bookable_online' => true], [$clinician]);
        $rule = $this->rule($clinician, $location, 1, '09:00', '12:00', ['is_bookable_online' => false]);

        $this->assertTrue($this->slots($service, '2026-10-05', online: true)->isEmpty());
        $this->assertCount(9, $this->slots($service, '2026-10-05'));

        $rule->forceFill(['is_bookable_online' => true])->save();
        $this->assertCount(9, $this->slots($service, '2026-10-05', online: true));

        $service->forceFill(['is_bookable_online' => false])->save();
        $this->assertTrue($this->slots($service, '2026-10-05', online: true)->isEmpty());
        $this->assertCount(9, $this->slots($service, '2026-10-05'));
    }

    #[Test]
    public function an_any_modality_rule_offers_in_person_and_telehealth_and_filters_narrow_it(): void
    {
        $this->useOrganization('Africa/Lagos'); // UTC+1, location in Accra (UTC+0)
        $this->setting('scheduling.slot_interval_minutes', '60');
        $clinician = $this->clinician();
        $accra = $this->location('Africa/Accra');
        $service = $this->service(['duration_minutes' => 60, 'allows_in_person' => true, 'allows_telehealth' => true], [$clinician]);
        $this->rule($clinician, $accra, 1, '09:00', '10:00', ['modality' => AvailabilityModality::Any]);

        $all = $this->slots($service, '2026-10-05');
        $this->assertCount(2, $all);
        [$inPerson, $telehealth] = $all->slots;
        $this->assertSame([Modality::InPerson, $accra->id, 'Africa/Accra'], [$inPerson->modality, $inPerson->locationId, $inPerson->timezone]);
        // Telehealth has no location and shows in the organization's timezone; the instant is the same.
        $this->assertSame([Modality::Telehealth, null, 'Africa/Lagos'], [$telehealth->modality, $telehealth->locationId, $telehealth->timezone]);
        $this->assertTrue($inPerson->startsAt->equalTo($telehealth->startsAt));

        $this->assertSame([Modality::Telehealth], array_map(fn ($s) => $s->modality, $this->slots($service, '2026-10-05', modality: Modality::Telehealth)->slots));
        // A location means "in person there"…
        $this->assertSame([Modality::InPerson], array_map(fn ($s) => $s->modality, $this->slots($service, '2026-10-05', location: $accra)->slots));
        // …and is ignored for telehealth, which has no location.
        $this->assertCount(1, $this->slots($service, '2026-10-05', location: $accra, modality: Modality::Telehealth));

        $inPersonOnly = $this->service(['duration_minutes' => 60, 'allows_in_person' => true, 'allows_telehealth' => false], [$clinician]);
        $this->assertSame([Modality::InPerson], array_map(fn ($s) => $s->modality, $this->slots($inPersonOnly, '2026-10-05')->slots));
    }

    #[Test]
    public function a_service_tied_to_locations_is_offered_in_person_only_there(): void
    {
        $this->setting('scheduling.slot_interval_minutes', '60');
        $clinician = $this->clinician();
        $osu = $this->location();
        $labone = $this->location();
        $service = $this->service(['duration_minutes' => 60, 'allows_telehealth' => false], [$clinician], [$osu]);
        $this->rule($clinician, $osu, 1, '09:00', '10:00');
        $this->rule($clinician, $labone, 2, '09:00', '10:00');

        $result = $this->slots($service, '2026-10-05', '2026-10-06');
        $this->assertSame(['2026-10-05 09:00'], $this->startsUtc($result));
        $this->assertTrue($this->slots($service, '2026-10-06', location: $labone)->isEmpty());
    }

    #[Test]
    public function results_are_sorted_by_start_then_clinician_and_can_be_narrowed_to_one_clinician(): void
    {
        $this->setting('scheduling.slot_interval_minutes', '60');
        [$a, $b] = [$this->clinician(), $this->clinician()];
        $location = $this->location();
        $service = $this->service(['duration_minutes' => 60], [$a, $b]);
        $this->rule($a, $location, 1, '10:00', '12:00');
        $this->rule($b, $location, 1, '09:00', '11:00');

        $result = $this->slots($service, '2026-10-05');
        $expected = [['09:00', $b->id], ['10:00', min($a->id, $b->id)], ['10:00', max($a->id, $b->id)], ['11:00', $a->id]];
        $this->assertSame($expected, array_map(fn ($s) => [$s->startsAt->format('H:i'), $s->clinicianMembershipId], $result->slots));

        $this->assertSame([$a->id], $this->slots($service, '2026-10-05', clinician: $a)->clinicianIds());
    }

    #[Test]
    public function overlapping_rules_of_one_clinician_do_not_duplicate_slots(): void
    {
        $this->setting('scheduling.slot_interval_minutes', '60');
        $clinician = $this->clinician();
        $location = $this->location();
        $service = $this->service(['duration_minutes' => 60], [$clinician]);
        $this->rule($clinician, $location, 1, '09:00', '11:00');
        $this->rule($clinician, $location, 1, '10:00', '12:00');

        $this->assertSame(['2026-10-05 09:00', '2026-10-05 10:00', '2026-10-05 11:00'], $this->startsUtc($this->slots($service, '2026-10-05')));
    }

    #[Test]
    public function the_search_is_three_bounded_queries_whatever_the_range_or_the_number_of_clinicians(): void
    {
        $clinicians = [$this->clinician(), $this->clinician(), $this->clinician()];
        $location = $this->location();
        $service = $this->service(['duration_minutes' => 50], $clinicians);
        foreach ($clinicians as $i => $clinician) {
            foreach (range(1, 5) as $weekday) {
                $this->rule($clinician, $location, $weekday, '08:00', '17:00');
            }
            foreach (range(5, 30, 3) as $day) {
                Appointment::factory()->for($clinician, 'clinician')->for($service)->for($location)
                    ->at(CarbonImmutable::parse(sprintf('2026-10-%02d 1%d:00', $day, $i), 'UTC'))->create();
            }
            BlockedTimeFactory::new()->between(CarbonImmutable::parse('2026-10-14 08:00', 'UTC'), CarbonImmutable::parse('2026-10-14 12:00', 'UTC'))
                ->create(['membership_id' => $clinician->id]);
        }
        BlockedTimeFactory::new()->between(CarbonImmutable::parse('2026-10-20 00:00', 'UTC'), CarbonImmutable::parse('2026-10-21 00:00', 'UTC'))->create();
        app(SettingsService::class)->organization($this->organization, 'scheduling.slot_interval_minutes'); // warm the per-request settings

        $oneDay = $this->queriesDuring(fn () => $this->assertNotEmpty($this->slots($service, '2026-10-05')->slots));
        $month = $this->queriesDuring(fn () => $this->assertNotEmpty($this->slots($service, '2026-10-01', '2026-10-31')->slots));

        $this->assertCount(count($oneDay), $month, 'A month costs the same queries as a day.');
        $tables = array_map(fn (string $sql) => match (true) {
            str_contains($sql, 'from "availability_rules"') => 'rules',
            str_contains($sql, 'from "blocked_times"') => 'blocked_times',
            str_contains($sql, 'from "appointments"') => 'appointments',
            str_contains($sql, 'from "service_locations"') => 'service_locations lookup',
            default => $sql,
        }, $month);
        sort($tables);
        $this->assertSame(['appointments', 'blocked_times', 'rules', 'service_locations lookup'], $tables);
    }

    #[Test]
    public function the_range_is_validated(): void
    {
        $service = $this->service();

        foreach ([['2026-10-01', '2026-11-01', 'range_too_long'], ['2026-10-05', '2026-10-04', 'invalid_range'], ['2026-02-30', '2026-03-01', 'invalid_date']] as [$from, $to, $code]) {
            try {
                new SlotQuery($service, $from, $to);
                $this->fail("Expected {$code}");
            } catch (DomainException $e) {
                $this->assertSame($code, $e->errorCode());
            }
        }

        $this->assertSame(31, (new SlotQuery($service, '2026-10-01', '2026-10-31'))->days());
    }
}
