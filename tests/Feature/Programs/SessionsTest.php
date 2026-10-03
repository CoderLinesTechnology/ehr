<?php

namespace Tests\Feature\Programs;

use App\Domain\Programs\CancelProgramSession;
use App\Domain\Programs\ProgramStatus;
use App\Domain\Programs\RecordAttendance;
use App\Domain\Programs\ScheduleProgramSession;
use App\Domain\Scheduling\Calendar\CalendarFilters;
use App\Domain\Scheduling\Calendar\CalendarQuery;
use App\Domain\Scheduling\Calendar\CalendarRange;
use App\Domain\Shared\DomainException;
use App\Models\Program;
use App\Models\ProgramSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

/** Program sessions, attendance and their appearance on the central calendar. */
class SessionsTest extends ProgramsTestCase
{
    private function schedule(Program $program, array $input = []): ProgramSession
    {
        $input += ['title' => 'Group Therapy Session', 'date' => '2026-10-07', 'start_time' => '10:00', 'end_time' => '11:00', 'place' => 'online'];

        return $this->doAs(fn () => app(ScheduleProgramSession::class)($program, $input));
    }

    private function calendarFor($member, ?string $program = null): Collection
    {
        $this->app->forgetScopedInstances();

        return $this->doAs(function () use ($program) {
            $query = app(CalendarQuery::class);
            $now = CarbonImmutable::parse('2026-10-05 09:00', 'UTC');
            $filters = CalendarFilters::from(array_filter(['view' => 'week', 'date' => '2026-10-07', 'program' => $program]), $now);

            return $query->appointments($filters, CalendarRange::for($filters, $query->timezone(), 1));
        }, $member);
    }

    #[Test]
    public function a_session_is_stored_as_utc_with_its_timezone_and_audited(): void
    {
        $this->a->organization->forceFill(['timezone' => 'America/New_York'])->save();
        $program = $this->program();

        $session = $this->schedule($program, ['place' => '']);

        $this->assertSame('2026-10-07 14:00:00', $session->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('America/New_York', $session->timezone);
        $this->assertCount(1, $this->auditEntries('program_session.scheduled'));
    }

    #[Test]
    public function session_input_is_validated_and_bounded_by_the_program(): void
    {
        $program = $this->program(['ends_on' => '2026-12-31']);
        $closed = $this->program(status: ProgramStatus::OnHold);
        $bad = [
            'invalid_datetime' => ['date' => '2026-02-30'],
            'range' => ['end_time' => '09:00'],
            'place' => ['place' => (string) Str::uuid()],
            'outside_program_dates' => ['date' => '2027-01-02'],
            'required' => ['title' => ' '],
        ];

        foreach ($bad as $code => $override) {
            try {
                $this->schedule($program, $override);
                $this->fail("expected {$code}");
            } catch (DomainException $e) {
                $this->assertSame($code, $e->errorCode());
            }
        }

        try {
            $this->schedule($closed);
            $this->fail('closed program');
        } catch (DomainException $e) {
            $this->assertSame('program_closed', $e->errorCode());
        }
        $this->assertSame(0, DB::table('program_sessions')->count());
    }

    #[Test]
    public function program_sessions_appear_on_the_calendar_as_read_only_events(): void
    {
        $program = $this->program(['name' => 'Wellness Group', 'color' => 'green']);
        $session = $this->schedule($program);

        $events = $this->calendarFor($this->admin);
        $event = $events->firstWhere('id', $session->id);

        $this->assertNotNull($event);
        $this->assertSame('program', $event->kind);
        $this->assertSame('Group Therapy Session', $event->clientName);
        $this->assertSame('Wellness Group', $event->serviceName);
        $this->assertSame('Online', $event->locationLabel);
        $this->assertSame('green', $event->family);
        $this->assertSame($program->id, $event->programId);

        // The calendar page draws it, linking to the program (never to an appointment screen).
        $this->as($this->admin)->get($this->url('app.calendar.index', ['view' => 'week', 'date' => '2026-10-07']))
            ->assertOk()->assertSee('Group Therapy Session')->assertSee('/programs/'.$program->id.'/sessions/'.$session->id, false);
    }

    #[Test]
    public function calendar_visibility_and_filters_apply_to_program_sessions(): void
    {
        $program = $this->program();
        $other = $this->program();
        $mine = $this->schedule($program, ['facilitator_membership_id' => $this->clinician->id]);
        $this->schedule($other, ['title' => 'Other Session']);

        // Members without programs.view do not see program events; cancelled sessions leave the calendar.
        $this->assertCount(0, $this->calendarFor($this->none)->where('kind', 'program'));
        $this->assertCount(2, $this->calendarFor($this->admin)->where('kind', 'program'));

        // ?program=<id> is that program only; ?program=all is program sessions only.
        $this->assertSame([$mine->id], $this->calendarFor($this->admin, $program->id)->pluck('id')->all());
        $this->assertCount(2, $this->calendarFor($this->admin, 'all'));

        $this->doAs(fn () => app(CancelProgramSession::class)($mine));
        $this->assertCount(1, $this->calendarFor($this->admin)->where('kind', 'program'));
    }

    #[Test]
    public function another_organizations_sessions_never_reach_the_calendar(): void
    {
        $theirs = $this->program(in: $this->b);
        $this->doAs(fn () => app(ScheduleProgramSession::class)($theirs, ['title' => 'Secret', 'date' => '2026-10-07', 'start_time' => '10:00', 'end_time' => '11:00', 'place' => 'online']), in: $this->b);

        $this->assertCount(0, $this->calendarFor($this->admin)->where('title', 'Secret'));
        $this->as($this->admin)->get($this->url('app.calendar.index', ['view' => 'week', 'date' => '2026-10-07']))->assertDontSee('Secret');
    }

    #[Test]
    public function attendance_is_recorded_per_participant_of_that_program_and_can_be_corrected(): void
    {
        $program = $this->program();
        $other = $this->program();
        $a = $this->admit($program, $this->client());
        $b = $this->admit($program, $this->client());
        $stranger = $this->admit($other, $this->client());
        $session = $this->schedule($program, ['date' => '2026-10-05', 'start_time' => '07:00', 'end_time' => '08:00']);

        $written = $this->doAs(fn () => app(RecordAttendance::class)($session, [$a->id => 'present', $b->id => 'absent']));
        $this->assertSame(2, $written);

        // A correction changes the row; an unchanged one writes nothing; a foreign participant is refused outright.
        $this->assertSame(1, $this->doAs(fn () => app(RecordAttendance::class)($session, [$a->id => 'excused', $b->id => 'absent'])));
        $this->assertSame('excused', DB::table('program_session_attendance')->where('enrollment_id', $a->id)->value('status'));

        try {
            $this->doAs(fn () => app(RecordAttendance::class)($session, [$a->id => 'present', $stranger->id => 'present']));
            $this->fail('foreign participant');
        } catch (DomainException $e) {
            $this->assertSame('invalid_attendance', $e->errorCode());
        }
        $this->assertSame('excused', DB::table('program_session_attendance')->where('enrollment_id', $a->id)->value('status'), 'a refused request half-succeeds never');
        $this->assertSame(2, DB::table('program_session_attendance')->count());

        // The database also ties attendance to the session's own program.
        $this->expectException(QueryException::class);
        DB::table('program_session_attendance')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $this->a->organization->id, 'record_environment' => 'live', 'program_id' => $other->id,
            'session_id' => $session->id, 'enrollment_id' => $stranger->id, 'status' => 'present', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function attendance_needs_a_session_that_has_started_and_was_not_cancelled(): void
    {
        $program = $this->program();
        $enrollment = $this->admit($program, $this->client());
        $future = $this->schedule($program, ['date' => '2026-10-20']);

        foreach ([$future->id => 'session_not_started'] as $id => $code) {
            try {
                $this->doAs(fn () => app(RecordAttendance::class)($future, [$enrollment->id => 'present']));
                $this->fail('not started');
            } catch (DomainException $e) {
                $this->assertSame($code, $e->errorCode());
            }
        }

        $past = $this->schedule($program, ['date' => '2026-10-05', 'start_time' => '07:00', 'end_time' => '08:00']);
        DB::table('program_sessions')->where('id', $past->id)->update(['cancelled_at' => now()]);
        $this->expectException(DomainException::class);
        $this->doAs(fn () => app(RecordAttendance::class)($past->fresh(), [$enrollment->id => 'present']));
    }

    #[Test]
    public function the_attendance_sheet_form_saves_and_shows_what_was_recorded(): void
    {
        $program = $this->program();
        $enrollment = $this->admit($program, $this->client(['first_name' => 'Ada', 'last_name' => 'Attends']));
        $session = $this->schedule($program, ['date' => '2026-10-05', 'start_time' => '07:00', 'end_time' => '08:00']);

        $this->as($this->admin)->get($this->url('app.programs.sessions.show', ['program' => $program->id, 'session' => $session->id]))->assertOk()->assertSee('Ada Attends')->assertSee('Save attendance');
        $this->post($this->url('app.programs.sessions.attendance', ['program' => $program->id, 'session' => $session->id]), ['attendance' => [$enrollment->id => 'bogus']])->assertRedirect();
        $this->assertSame(0, DB::table('program_session_attendance')->count());
        $this->post($this->url('app.programs.sessions.attendance', ['program' => $program->id, 'session' => $session->id]), ['attendance' => [$enrollment->id => 'present']])->assertRedirect();
        $this->get($this->url('app.programs.sessions.show', ['program' => $program->id, 'session' => $session->id]))->assertSee('checked', false);
    }
}
