<?php

namespace Tests\Feature\Scheduling\Http;

use App\Domain\Scheduling\Calendar\CalendarFilters;
use App\Domain\Scheduling\Calendar\CalendarRange;
use App\Domain\Scheduling\Calendar\EventFamily;
use App\Domain\Scheduling\Calendar\EventLayout;
use App\Domain\Scheduling\Modality;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class CalendarPageTest extends SchedulingHttpTestCase
{
    private function week(array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->get($this->url('app.calendar.index', ['view' => 'week', 'date' => '2026-10-02'] + $query));
    }

    #[Test]
    public function the_week_view_draws_events_in_the_clinicians_colour_family_and_telehealth_purple(): void
    {
        $this->setting('general.time_format', 'g:i A');
        $this->book($this->drA, $this->clientA, '2026-10-01 09:00:00', Modality::InPerson);
        $this->book($this->drB, $this->clientB, '2026-10-01 11:00:00', Modality::InPerson);
        $this->book($this->drA, $this->clientB, '2026-10-02 13:30:00', Modality::Telehealth);

        $html = $this->as($this->adminMembership)->week()->assertOk()->getContent();

        $this->assertStringContainsString('Appointments', $html);
        $this->assertStringContainsString("Today's Appointments", $html);
        $this->assertMatchesRegularExpression('/cal-event cal-event--blue[^>]*>\s*<span class="cal-event__time">9:00 AM<\/span>\s*<span class="cal-event__client">Alice Alpha/', $html);
        $this->assertMatchesRegularExpression('/cal-event cal-event--orange[^>]*>\s*<span class="cal-event__time">11:00 AM/', $html);
        $this->assertMatchesRegularExpression('/cal-event cal-event--purple[^>]*>\s*<span class="cal-event__time">1:30 PM/', $html);
        $this->assertStringContainsString('Sep 28 – Oct 4, 2026', $html, 'en dash with spaces, Monday-first week');
        $this->assertStringNotContainsString('44:00', $html);
        $this->assertStringNotContainsString('{{--', $html);
    }

    #[Test]
    public function the_grid_covers_the_organizations_hours_and_places_events_by_time(): void
    {
        $this->setting('scheduling.calendar_day_start', '08:00');
        $this->setting('scheduling.calendar_day_end', '18:00');
        $this->book($this->drA, $this->clientA, '2026-10-01 09:00:00');
        $this->book($this->drB, $this->clientB, '2026-10-01 19:00:00'); // outside the visible hours

        $html = $this->as($this->adminMembership)->week()->assertOk()->getContent();

        $this->assertSame(10, substr_count($html, 'class="cal-hour"'), 'ten hour rows, 8:00 .. 17:00');
        $this->assertStringContainsString('--top:38.5px', $html, '9:00 is one row below 8:00');
        $this->assertStringContainsString('1 outside hours', $html);
        $this->assertStringNotContainsString('Bruno Bravo', $html, 'the 19:00 event is not drawn in a grid that ends at 18:00');
    }

    #[Test]
    public function day_and_month_views_render(): void
    {
        $this->book($this->drA, $this->clientA, '2026-10-02 09:00:00');

        $day = $this->as($this->adminMembership)->get($this->url('app.calendar.index', ['view' => 'day', 'date' => '2026-10-02']))->assertOk()->getContent();
        $this->assertStringContainsString('cal-grid--day', $day);
        $this->assertStringContainsString($this->drA->professionalName(), $day);
        $this->assertStringContainsString('Alice Alpha', $day);

        $month = $this->get($this->url('app.calendar.index', ['view' => 'month', 'date' => '2026-10-02']))->assertOk()->getContent();
        $this->assertStringContainsString('cal-month__day', $month);
        $this->assertStringContainsString('Alice Alpha', $month);
        $this->assertStringContainsString('October 2026', $month);
    }

    #[Test]
    public function malformed_query_values_fall_back_instead_of_failing(): void
    {
        $this->as($this->adminMembership)->get($this->url('app.calendar.index', [
            'view' => 'year', 'date' => '2026-02-31', 'clinician' => 'nope', 'service' => ['x'], 'status' => 'zzz', 'modality' => '1', 'mini' => 'abc',
        ]))->assertOk();
    }

    #[Test]
    public function filters_narrow_the_range_and_the_todays_table(): void
    {
        $this->book($this->drA, $this->clientA, '2026-10-02 09:00:00');
        $this->book($this->drB, $this->clientB, '2026-10-02 11:00:00');

        $html = $this->as($this->adminMembership)->week(['clinician' => $this->drB->id])->assertOk()->getContent();

        $this->assertStringContainsString('Bruno Bravo', $html);
        $this->assertStringNotContainsString('Alice Alpha', $html);
        $this->assertStringContainsString('Clear all', $html);
    }

    #[Test]
    public function cancelled_appointments_are_hidden_unless_the_status_filter_asks(): void
    {
        $appointment = $this->book($this->drA, $this->clientA, '2026-10-02 09:00:00');
        app(\App\Domain\Scheduling\TransitionAppointment::class)($appointment, \App\Domain\Scheduling\AppointmentStatus::Cancelled, $this->actor, null, 'practice');

        $this->as($this->adminMembership)->week()->assertOk()->assertDontSee('Alice Alpha');
        $this->week(['status' => 'cancelled'])->assertOk()->assertSee('Alice Alpha');
    }

    #[Test]
    public function the_mini_calendar_marks_days_with_appointments(): void
    {
        $this->book($this->drA, $this->clientA, '2026-10-01 09:00:00');

        $html = $this->as($this->adminMembership)->week()->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/aria-label="October 1, 2026, has appointments"/', $html);
        $this->assertMatchesRegularExpression('/aria-label="October 3, 2026"/', $html);
    }

    #[Test]
    public function quick_actions_only_offer_what_exists_and_is_permitted(): void
    {
        $html = $this->as($this->adminMembership)->week()->assertOk()->getContent();
        $this->assertStringContainsString('Create Appointment', $html);
        $this->assertStringContainsString('Add Availability', $html);
        $this->assertStringNotContainsString('Create Invoice', $html, 'no invoice route exists yet');

        $viewer = $this->addStaff($this->organization, 'staff');
        $html = $this->as($viewer)->week()->assertOk()->getContent();
        $this->assertStringNotContainsString('Create Appointment', $html);
        $this->assertStringNotContainsString('New Appointment', $html);
        $this->assertStringNotContainsString('Add Availability', $html);
    }

    #[Test]
    public function the_calendar_scripts_and_styles_are_files_not_inline(): void
    {
        $html = $this->as($this->adminMembership)->week()->assertOk()->getContent();

        $this->assertStringContainsString('css/screens/calendar.css', $html);
        $this->assertStringContainsString('js/calendar.js', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/', $html);
    }

    // ---- pure read-side logic -------------------------------------------------

    #[Test]
    public function overlapping_events_share_a_row_by_their_drawn_height_not_their_duration(): void
    {
        // 9:00-10:00 and 10:00-11:00 do not overlap in time, but a card is at least 67px (1.74 rows) tall.
        // Back-to-back appointments stack (side-by-side would read as a double-booking);
        // the earlier card stops just above the next one.
        $layout = EventLayout::place([
            ['key' => 'a', 'start' => 9 * 60, 'end' => 10 * 60],
            ['key' => 'b', 'start' => 10 * 60, 'end' => 11 * 60],
            ['key' => 'c', 'start' => 15 * 60, 'end' => 16 * 60],
        ], 8 * 60, 18 * 60, 38.5, 67.0);

        $this->assertSame(1, $layout['placed']['a']['lanes']);
        $this->assertSame(0, $layout['placed']['b']['lane']);
        $this->assertSame(38.5, $layout['placed']['a']['top']);
        $this->assertSame(36.5, $layout['placed']['a']['height']);
        $this->assertSame(67.0, $layout['placed']['c']['height']);

        // A real overlap goes side by side.
        $overlap = EventLayout::place([
            ['key' => 'x', 'start' => 9 * 60, 'end' => 10 * 60],
            ['key' => 'y', 'start' => 9 * 60 + 30, 'end' => 10 * 60 + 30],
        ], 8 * 60, 18 * 60, 38.5, 67.0);

        $this->assertSame(2, $overlap['placed']['x']['lanes']);
        $this->assertSame(1, $overlap['placed']['y']['lane']);
        $this->assertSame(67.0, $overlap['placed']['x']['height']);
    }

    #[Test]
    public function a_late_card_pulled_up_at_the_bottom_never_covers_the_previous_appointment(): void
    {
        // 4–5 PM then 5:30 PM in a grid ending at 6 PM (the Monday column of the comp's week).
        $layout = EventLayout::place([
            ['key' => 'four', 'start' => 16 * 60, 'end' => 17 * 60],
            ['key' => 'half5', 'start' => 17 * 60 + 30, 'end' => 18 * 60 + 30],
        ], 8 * 60, 18 * 60, 38.5, 67.0);

        $four = $layout['placed']['four'];
        $late = $layout['placed']['half5'];

        $this->assertSame(1, $four['lanes']);
        $this->assertSame(308.0, $four['top']);
        $this->assertGreaterThanOrEqual(38.5, $four['height']);          // its full hour stays visible
        $this->assertGreaterThanOrEqual($four['top'] + $four['height'], $late['top']); // no overlap
        $this->assertEqualsWithDelta(385.0, $late['top'] + $late['height'], 0.01);  // stays inside the grid
    }

    #[Test]
    public function events_outside_the_window_are_counted_not_drawn(): void
    {
        $layout = EventLayout::place([
            ['key' => 'early', 'start' => 6 * 60, 'end' => 7 * 60],
            ['key' => 'late', 'start' => 19 * 60, 'end' => 20 * 60],
            ['key' => 'in', 'start' => 12 * 60, 'end' => 13 * 60],
        ], 8 * 60, 18 * 60, 38.5, 67.0);

        $this->assertSame(['in'], array_keys($layout['placed']));
        $this->assertSame([1, 1], [$layout['before'], $layout['after']]);
    }

    #[Test]
    public function a_card_that_would_overflow_the_bottom_is_pulled_up_to_stay_whole(): void
    {
        $layout = EventLayout::place([['key' => 'x', 'start' => 17 * 60 + 30, 'end' => 18 * 60]], 8 * 60, 18 * 60, 38.5, 67.0);

        $this->assertEqualsWithDelta(385.0, $layout['placed']['x']['top'] + $layout['placed']['x']['height'], 0.01);
        $this->assertSame(67.0, $layout['placed']['x']['height']);
    }

    /** @return array<string, array{string, string}> */
    public static function colours(): array
    {
        return ['blue' => ['#307EF6', 'blue'], 'green' => ['#16B482', 'green'], 'orange' => ['#F59E0B', 'orange'], 'teal' => ['#14B8A6', 'teal'], 'purple' => ['#8B5CF6', 'purple'], 'red snaps to orange' => ['#EF4444', 'orange'], 'grey is blue' => ['#888888', 'blue'], 'junk is blue' => ['zzz', 'blue']];
    }

    #[Test]
    #[DataProvider('colours')]
    public function a_clinician_colour_snaps_to_a_family(string $hex, string $family): void
    {
        $this->assertSame($family, EventFamily::forColor($hex));
    }

    #[Test]
    public function the_range_follows_the_organizations_week_start_and_timezone(): void
    {
        $filters = CalendarFilters::from(['view' => 'week', 'date' => '2026-10-02'], CarbonImmutable::parse('2026-10-02', 'UTC'));

        $monday = CalendarRange::for($filters, 'America/New_York', 1);
        $this->assertSame('2026-09-28', $monday->first()->format('Y-m-d'));
        $this->assertSame('2026-09-28 04:00:00', $monday->startsAt->format('Y-m-d H:i:s'), 'local midnight in New York is 04:00 UTC');

        $sunday = CalendarRange::for($filters, 'UTC', 7);
        $this->assertSame('2026-09-27', $sunday->first()->format('Y-m-d'));
        $this->assertCount(7, $sunday->days);

        $month = CalendarRange::for(CalendarFilters::from(['view' => 'month', 'date' => '2026-02-10'], CarbonImmutable::now()), 'UTC', 1);
        $this->assertCount(35, $month->days, 'February 2026 starts on a Sunday: Monday-first grid is Jan 26 - Mar 1');
    }
}
