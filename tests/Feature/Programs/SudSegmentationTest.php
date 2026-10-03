<?php

namespace Tests\Feature\Programs;

use App\Domain\Programs\DischargeClient;
use App\Domain\Programs\ProgramBoard;
use App\Domain\Programs\ProgramFilters;
use App\Domain\Programs\ProgramVisibility;
use App\Domain\Programs\PutEnrollmentOnHold;
use App\Domain\Programs\ScheduleProgramSession;
use App\Domain\Shared\DomainException;
use App\Models\ProgramEnrollment;
use App\Models\ProgramSession;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * 42 CFR Part 2: the participants of a program flagged as substance-use treatment are visible only with
 * programs.view_sud: lists, counts, search, detail pages, every action and the audit trail.
 */
class SudSegmentationTest extends ProgramsTestCase
{
    private function fixture(): array
    {
        $sud = $this->program(['name' => 'Recovery Support'], sud: true);
        $plain = $this->program(['name' => 'General Wellness']);
        $ann = $this->client(['first_name' => 'Annabel', 'last_name' => 'Sudwell']);
        $bob = $this->client(['first_name' => 'Robert', 'last_name' => 'Plainman']);
        $sudEnrollment = $this->admit($sud, $ann);
        $plainEnrollment = $this->admit($plain, $bob);

        return [$sud, $plain, $ann, $bob, $sudEnrollment, $plainEnrollment];
    }

    #[Test]
    public function participants_of_a_flagged_program_are_hidden_without_view_sud_everywhere(): void
    {
        [$sud, $plain, , , $sudEnrollment, $plainEnrollment] = $this->fixture();

        // The manager sees programs and non-flagged participants; nothing about the flagged ones.
        $this->as($this->manager);
        $this->get($this->url('app.programs.show', ['program' => $sud->id, 'tab' => 'participants']))
            ->assertOk()->assertSee('Participants are restricted')->assertDontSee('Annabel')->assertDontSee('Sudwell');
        $this->get($this->url('app.programs.show', ['program' => $sud->id]))->assertOk()->assertDontSee('Annabel');
        $this->get($this->url('app.programs.show', ['program' => $plain->id, 'tab' => 'participants']))->assertOk()->assertSee('Robert Plainman');

        // Their detail page does not exist for the manager; actions on it are 404 too.
        $this->get($this->url('app.programs.enrollments.show', ['program' => $sud->id, 'enrollment' => $sudEnrollment->id]))->assertNotFound();
        $this->post($this->url('app.programs.enrollments.hold', ['program' => $sud->id, 'enrollment' => $sudEnrollment->id]))->assertNotFound();
        $this->get($this->url('app.programs.enrollments.show', ['program' => $plain->id, 'enrollment' => $plainEnrollment->id]))->assertOk();

        // The supervisor (view_sud) sees both.
        $this->as($this->supervisor)->get($this->url('app.programs.show', ['program' => $sud->id, 'tab' => 'participants']))->assertOk()->assertSee('Annabel Sudwell');
        $this->get($this->url('app.programs.enrollments.show', ['program' => $sud->id, 'enrollment' => $sudEnrollment->id]))->assertOk()->assertSee('Admitted');
    }

    #[Test]
    public function counts_and_totals_leave_out_the_flagged_program_without_view_sud(): void
    {
        [$sud, $plain] = $this->fixture();
        $this->admit($sud, $this->client());
        $this->admit($plain, $this->client());

        $this->as($this->manager);
        $board = $this->boardAs($this->manager);
        $cards = $board['cards']->getCollection()->keyBy('name');
        $this->assertNull($cards['Recovery Support']->participant_count, 'no number at all for the flagged program');
        $this->assertSame(2, $cards['General Wellness']->participant_count);
        $this->assertSame(2, $board['stats']['participants'], 'the total leaves the flagged participants out');

        $board = $this->boardAs($this->supervisor);
        $cards = $board['cards']->getCollection()->keyBy('name');
        $this->assertSame(2, $cards['Recovery Support']->participant_count);
        $this->assertSame(4, $board['stats']['participants']);

        $this->as($this->manager)->get($this->url('app.programs.index'))->assertSee('Restricted');
    }

    #[Test]
    public function the_participant_search_never_finds_a_flagged_participant_without_view_sud(): void
    {
        [$sud, $plain] = $this->fixture();

        $this->as($this->manager)->get($this->url('app.programs.show', ['program' => $sud->id, 'tab' => 'participants', 'q' => 'Annabel']))->assertDontSee('Annabel');
        $this->get($this->url('app.programs.show', ['program' => $plain->id, 'tab' => 'participants', 'q' => 'Robert']))->assertSee('Robert Plainman');
        $this->as($this->supervisor)->get($this->url('app.programs.show', ['program' => $sud->id, 'tab' => 'participants', 'q' => 'annab']))->assertSee('Annabel Sudwell');

        // The visibility rule at query level, for any future search that goes through it.
        $found = fn ($member) => $this->doAs(fn () => ProgramVisibility::enrollments(ProgramEnrollment::query(), $this->membershipRecord($member->id))->count(), $member);
        $this->assertSame(1, $found($this->manager));
        $this->assertSame(2, $found($this->supervisor));
        $this->assertSame(0, $found($this->none));
    }

    #[Test]
    public function the_admit_screen_and_actions_refuse_a_flagged_program_without_view_sud(): void
    {
        [$sud] = $this->fixture();
        $client = $this->client();

        $this->as($this->manager)->get($this->url('app.programs.admit', ['program' => $sud->id]))->assertOk()->assertDontSee('Recovery Support');
        $this->post($this->url('app.programs.admit.store'), ['program_id' => $sud->id, 'client_id' => $client->id])->assertForbidden();
        $this->assertSame(1, DB::table('program_enrollments')->where('program_id', $sud->id)->count());

        $this->as($this->supervisor)->post($this->url('app.programs.admit.store'), ['program_id' => $sud->id, 'client_id' => $client->id])->assertRedirect();
        $this->assertSame(2, DB::table('program_enrollments')->where('program_id', $sud->id)->count());
    }

    #[Test]
    public function domain_actions_refuse_a_flagged_enrollment_without_view_sud_even_if_called_directly(): void
    {
        [$sud, , , , $sudEnrollment] = $this->fixture();

        foreach ([
            fn () => app(PutEnrollmentOnHold::class)($sudEnrollment, 'x'),
            fn () => app(DischargeClient::class)($sudEnrollment, 'completed'),
        ] as $attempt) {
            try {
                $this->doAs($attempt, $this->manager);
                $this->fail('refused');
            } catch (DomainException|ModelNotFoundException $e) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame('active', $sudEnrollment->fresh()->status->value);
    }

    #[Test]
    public function attendance_of_a_flagged_program_is_hidden_and_unrecordable_without_view_sud(): void
    {
        [$sud, , , , $enrollment] = $this->fixture();
        $session = $this->pastSession($sud);

        $this->as($this->manager)->get($this->url('app.programs.sessions.show', ['program' => $sud->id, 'session' => $session->id]))
            ->assertOk()->assertSee('restricted')->assertDontSee('Annabel');
        $this->post($this->url('app.programs.sessions.attendance', ['program' => $sud->id, 'session' => $session->id]), ['attendance' => [$enrollment->id => 'present']])->assertForbidden();
        $this->assertSame(0, DB::table('program_session_attendance')->count());

        $this->as($this->supervisor)->post($this->url('app.programs.sessions.attendance', ['program' => $sud->id, 'session' => $session->id]), ['attendance' => [$enrollment->id => 'present']])->assertRedirect();
        $this->assertSame(1, DB::table('program_session_attendance')->count());
    }

    #[Test]
    public function the_audit_trail_names_neither_the_client_nor_the_flagged_program(): void
    {
        [$sud, , $ann, , $enrollment] = $this->fixture();
        $this->doAs(fn () => app(PutEnrollmentOnHold::class)($enrollment, 'a private reason'));

        $trail = DB::table('audit_logs')->where('action', 'like', 'program_enrollment.%')->get()->map(fn ($r) => json_encode($r))->implode(' ');
        $this->assertStringNotContainsString($ann->last_name, $trail);
        $this->assertStringNotContainsString($ann->first_name, $trail);
        $this->assertStringNotContainsString('a private reason', $trail);
        $this->assertStringNotContainsString('Recovery Support', $trail);
        $this->assertStringContainsString('restricted program', $trail);
    }

    /** @return array<string, mixed> */
    private function boardAs($member): array
    {
        $this->app->forgetScopedInstances();

        return $this->doAs(fn () => app(ProgramBoard::class)(ProgramFilters::from([])), $member);
    }

    private function pastSession($program): ProgramSession
    {
        return $this->doAs(function () use ($program) {
            $session = app(ScheduleProgramSession::class)($program, ['title' => 'Group', 'date' => '2026-10-05', 'start_time' => '07:00', 'end_time' => '08:00', 'place' => 'online']);

            return $session;
        });
    }
}
