<?php

namespace Tests\Feature\Programs;

use App\Domain\Programs\DischargeClient;
use App\Domain\Programs\EnrollmentStatus;
use App\Domain\Programs\ProgramStatus;
use App\Domain\Programs\PutEnrollmentOnHold;
use App\Domain\Programs\ResumeEnrollment;
use App\Domain\Programs\TransferClient;
use App\Domain\Programs\TransitionLevelOfCare;
use App\Domain\Shared\DomainException;
use App\Models\ProgramEnrollment;
use App\Models\ProgramEnrollmentEvent;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/** Admission, transitions and their insert-only history; the database's own rules. */
class EnrollmentTest extends ProgramsTestCase
{
    private function events(ProgramEnrollment $e): array
    {
        return DB::table('program_enrollment_events')->where('enrollment_id', $e->id)->orderBy('seq')->get()->all();
    }

    #[Test]
    public function admission_creates_an_active_enrollment_with_its_first_event_and_an_audit_entry(): void
    {
        $program = $this->program();
        $level = $this->level($program);
        $client = $this->client();

        $enrollment = $this->admit($program, $client, $level);

        $this->assertSame('active', $enrollment->status->value);
        $this->assertSame($level->id, $enrollment->current_level_id);
        $events = $this->events($enrollment);
        $this->assertCount(1, $events);
        $this->assertSame('admitted', $events[0]->event_type);
        $this->assertSame($level->id, $events[0]->to_level_id);
        $this->assertCount(1, $this->auditEntries('program_enrollment.admitted'));
        $this->assertStringNotContainsString($client->last_name, json_encode($this->auditEntries('program_enrollment.admitted')));
    }

    #[Test]
    public function a_program_with_levels_needs_one_and_a_foreign_level_is_refused(): void
    {
        $program = $this->program();
        $other = $this->program();
        $this->level($program);
        $foreign = $this->level($other, 'Elsewhere');

        foreach ([null, $foreign] as $level) {
            try {
                $this->admit($program, $this->client(), $level);
                $this->fail('refused');
            } catch (DomainException $e) {
                $this->assertContains($e->errorCode(), ['level_required', 'invalid_level']);
            }
        }
    }

    #[Test]
    public function only_upcoming_or_active_programs_admit_and_only_active_or_pending_clients_are_admitted(): void
    {
        $hold = $this->program(status: ProgramStatus::OnHold);
        $active = $this->program();

        try {
            $this->admit($hold, $this->client());
            $this->fail('program on hold');
        } catch (DomainException $e) {
            $this->assertSame('program_closed', $e->errorCode());
        }

        try {
            $this->admit($active, $this->client(['status' => 'inactive']));
            $this->fail('inactive client');
        } catch (DomainException $e) {
            $this->assertSame('client_not_eligible', $e->errorCode());
        }
    }

    #[Test]
    public function one_open_enrollment_per_client_per_program_is_enforced_by_the_database(): void
    {
        $program = $this->program();
        $client = $this->client();
        $first = $this->admit($program, $client);

        // The action says so kindly ...
        try {
            $this->admit($program, $client);
            $this->fail('duplicate');
        } catch (DomainException $e) {
            $this->assertSame('already_enrolled', $e->errorCode());
        }

        // ... and the database refuses even a writer that skips the action.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('program_enrollments_one_open');
        DB::table('program_enrollments')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $first->organization_id, 'record_environment' => 'live',
            'client_id' => $client->id, 'program_id' => $program->id, 'status' => 'on_hold', 'admitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_closed_enrollment_does_not_block_a_returning_client(): void
    {
        $program = $this->program();
        $client = $this->client();
        $first = $this->admit($program, $client);
        $this->doAs(fn () => app(DischargeClient::class)($first, 'discharged', 'moved away'));

        $again = $this->admit($program, $client);

        $this->assertNotSame($first->id, $again->id);
        $this->assertSame(2, DB::table('program_enrollments')->where('client_id', $client->id)->count());
    }

    #[Test]
    public function transitions_write_events_in_order_and_never_overwrite_history(): void
    {
        $program = $this->program();
        $one = $this->level($program, 'Level I');
        $two = $this->level($program, 'Level II');
        $enrollment = $this->admit($program, $this->client(), $one);

        $this->doAs(fn () => app(TransitionLevelOfCare::class)($enrollment, $two, 'stepping up after review'));
        $this->doAs(fn () => app(PutEnrollmentOnHold::class)($enrollment, 'travelling'));
        $this->doAs(fn () => app(ResumeEnrollment::class)($enrollment));
        $this->doAs(fn () => app(DischargeClient::class)($enrollment, 'completed'));

        $events = $this->events($enrollment);
        $this->assertSame(['admitted', 'level_changed', 'put_on_hold', 'resumed', 'completed'], array_column($events, 'event_type'));
        $this->assertSame(['active', 'on_hold', 'active', 'completed'], array_column(array_slice($events, 1), 'to_status'));
        $this->assertSame($one->id, $events[1]->from_level_id);
        $this->assertSame($two->id, $events[1]->to_level_id);
        $this->assertSame('stepping up after review', $events[1]->reason);
        $this->assertNotNull($events[1]->authorized_by_membership_id);
        // The earlier rows are exactly as they were written: history only grows.
        $this->assertSame('admitted', $events[0]->event_type);
        $this->assertSame($one->id, $events[0]->to_level_id);

        $row = DB::table('program_enrollments')->where('id', $enrollment->id)->first();
        $this->assertSame('completed', $row->status);
        $this->assertSame($two->id, $row->current_level_id);
        $this->assertNotNull($row->ended_at);
        $this->assertSame('completed', $enrollment->status->value, 'the caller\'s instance is handed back in sync');
    }

    #[Test]
    public function the_history_table_refuses_updates_and_deletes_even_from_raw_sql(): void
    {
        $enrollment = $this->admit($this->program(), $this->client());
        $eventId = $this->events($enrollment)[0]->id;

        foreach (["UPDATE program_enrollment_events SET reason = 'x' WHERE id = ?", 'DELETE FROM program_enrollment_events WHERE id = ?'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql, [$eventId]));
                $this->fail('insert-only');
            } catch (QueryException $e) {
                $this->assertStringContainsString('insert-only', $e->getMessage());
            }
        }

        $model = $this->inTenant($this->a->organization, fn () => ProgramEnrollmentEvent::query()->findOrFail($eventId));
        $this->expectException(\LogicException::class);
        $model->update(['reason' => 'edit']);
    }

    #[Test]
    public function a_discharge_needs_a_reason_but_completion_does_not(): void
    {
        $program = $this->program();
        $enrollment = $this->admit($program, $this->client());

        try {
            $this->doAs(fn () => app(DischargeClient::class)($enrollment, 'discharged', '  '));
            $this->fail('reason required');
        } catch (DomainException $e) {
            $this->assertSame('reason_required', $e->errorCode());
        }
        $this->assertSame('active', $enrollment->fresh()->status->value);

        $this->doAs(fn () => app(DischargeClient::class)($enrollment, 'discharged', 'client moved away'));
        $this->assertSame('discharged', $enrollment->fresh()->status->value);

        $other = $this->admit($program, $this->client());
        $this->doAs(fn () => app(DischargeClient::class)($other, 'completed'));
        $this->assertSame('completed', $other->fresh()->status->value);
    }

    #[Test]
    public function the_discharge_form_refuses_a_missing_reason_with_a_message(): void
    {
        $program = $this->program();
        $enrollment = $this->admit($program, $this->client());

        $this->as($this->admin)->from($this->url('app.programs.enrollments.show', ['program' => $program->id, 'enrollment' => $enrollment->id]))
            ->post($this->url('app.programs.enrollments.discharge', ['program' => $program->id, 'enrollment' => $enrollment->id]), ['outcome' => 'discharged', 'reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->assertSame('active', $enrollment->fresh()->status->value);
    }

    #[Test]
    public function a_level_change_needs_a_reason_a_different_level_of_the_same_program_and_a_real_authorizer(): void
    {
        $program = $this->program();
        $one = $this->level($program, 'Level I');
        $two = $this->level($program, 'Level II');
        $foreign = $this->level($this->program(), 'Elsewhere');
        $enrollment = $this->admit($program, $this->client(), $one);

        $attempts = [
            'reason_required' => fn () => app(TransitionLevelOfCare::class)($enrollment, $two, ''),
            'same_level' => fn () => app(TransitionLevelOfCare::class)($enrollment, $one, 'because'),
            'invalid_level' => fn () => app(TransitionLevelOfCare::class)($enrollment, $foreign, 'because'),
            'invalid_authorizer' => fn () => app(TransitionLevelOfCare::class)($enrollment, $two, 'because', $this->none),
        ];
        foreach ($attempts as $code => $attempt) {
            try {
                $this->doAs($attempt);
                $this->fail("expected {$code}");
            } catch (DomainException $e) {
                $this->assertSame($code, $e->errorCode());
            }
        }
        $this->assertCount(1, $this->events($enrollment));

        $this->doAs(fn () => app(TransitionLevelOfCare::class)($enrollment, $two, 'because', $this->clinician));
        $this->assertSame($this->clinician->id, $this->events($enrollment)[1]->authorized_by_membership_id);
    }

    /** The allowed enrollment moves, spelled out by hand. */
    public static function enrollmentMoves(): array
    {
        $allowed = [
            'pending' => ['active', 'discharged'],
            'active' => ['on_hold', 'completed', 'discharged', 'transferred'],
            'on_hold' => ['active', 'completed', 'discharged', 'transferred'],
            'completed' => [], 'discharged' => [], 'transferred' => [],
        ];
        $cases = [];
        foreach ($allowed as $from => $tos) {
            foreach (array_keys($allowed) as $to) {
                if ($from !== $to) {
                    $cases["{$from} -> {$to}"] = [$from, $to, in_array($to, $tos, true)];
                }
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('enrollmentMoves')]
    public function the_enrollment_state_machine_matches_its_specification(string $from, string $to, bool $allowed): void
    {
        $this->assertSame($allowed, EnrollmentStatus::from($from)->canMoveTo(EnrollmentStatus::from($to)));
    }

    #[Test]
    public function a_closed_enrollment_cannot_be_changed(): void
    {
        $enrollment = $this->admit($this->program(), $this->client());
        $this->doAs(fn () => app(DischargeClient::class)($enrollment, 'completed'));

        $this->expectException(DomainException::class);
        $this->doAs(fn () => app(PutEnrollmentOnHold::class)($enrollment, 'again'));
    }

    #[Test]
    public function a_transfer_closes_one_enrollment_and_opens_the_other_atomically(): void
    {
        $from = $this->program();
        $to = $this->program();
        $level = $this->level($to, 'Level I');
        $client = $this->client();
        $old = $this->admit($from, $client);

        $new = $this->doAs(fn () => app(TransferClient::class)($old, $to, $level, 'needs a different setting'));

        $this->assertSame('transferred', $old->fresh()->status->value);
        $this->assertSame('active', $new->status->value);
        $this->assertSame($to->id, $new->program_id);
        $this->assertSame($new->id, $this->events($old)[1]->related_enrollment_id);
        $this->assertSame($old->id, $this->events($new)[0]->related_enrollment_id);

        // A refusal on the target (level required) rolls the source back.
        $third = $this->program();
        $this->level($third, 'Level I');
        $other = $this->admit($from, $this->client());
        try {
            $this->doAs(fn () => app(TransferClient::class)($other, $third, null));
            $this->fail('level required');
        } catch (DomainException) {
        }
        $this->assertSame('active', $other->fresh()->status->value);
        $this->assertCount(1, $this->events($other));
    }

    #[Test]
    public function the_enrollment_carries_the_clients_environment_and_the_database_refuses_a_mismatch(): void
    {
        $program = $this->program();
        $live = $this->admit($program, $this->client());
        $demo = $this->admit($program, $this->client(demo: true));

        $this->assertSame('live', $live->record_environment->value);
        $this->assertSame('demo', $demo->record_environment->value);
        $this->assertSame('demo', $this->events($demo)[0]->record_environment);

        // A raw insert claiming "live" for a demo client violates the composite foreign key.
        $this->expectException(QueryException::class);
        DB::table('program_enrollments')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $demo->organization_id, 'record_environment' => 'live',
            'client_id' => $demo->client_id, 'program_id' => $this->program()->id, 'status' => 'active', 'admitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function an_enrollment_cannot_point_at_a_client_or_program_of_another_organization(): void
    {
        $theirs = $this->program(in: $this->b);
        $mine = $this->client();

        $this->expectException(QueryException::class);
        DB::table('program_enrollments')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $this->b->organization->id, 'record_environment' => 'live',
            'client_id' => $mine->id, 'program_id' => $theirs->id, 'status' => 'active', 'admitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function an_enrollment_level_must_belong_to_its_program(): void
    {
        $program = $this->program();
        $foreign = $this->level($this->program(), 'Elsewhere');
        $client = $this->client();

        $this->expectException(QueryException::class);
        DB::table('program_enrollments')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $this->a->organization->id, 'record_environment' => 'live',
            'client_id' => $client->id, 'program_id' => $program->id, 'current_level_id' => $foreign->id, 'status' => 'active', 'admitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function the_clinician_may_only_admit_their_own_clients(): void
    {
        $program = $this->program();
        $mine = $this->client(['primary_clinician_membership_id' => $this->clinician->id]);
        $someoneElses = $this->client();

        $this->admit($program, $mine, by: $this->clinician);

        $this->expectException(ModelNotFoundException::class);
        $this->admit($program, $someoneElses, by: $this->clinician);
    }

    #[Test]
    public function admission_through_the_form_lists_only_visible_clients_and_redirects_to_the_participants(): void
    {
        $program = $this->program();
        $mine = $this->client(['first_name' => 'Visible', 'last_name' => 'Person', 'primary_clinician_membership_id' => $this->clinician->id]);
        $this->client(['first_name' => 'Hidden', 'last_name' => 'Person']);

        $this->as($this->clinician)->get($this->url('app.programs.admit', ['program' => $program->id]))
            ->assertOk()->assertSee('Visible Person')->assertDontSee('Hidden Person');

        $this->post($this->url('app.programs.admit.store'), ['program_id' => $program->id, 'client_id' => $mine->id])
            ->assertRedirect($this->url('app.programs.show', ['program' => $program->id, 'tab' => 'participants']));
        $this->assertSame(1, DB::table('program_enrollments')->where('program_id', $program->id)->count());
    }

    #[Test]
    public function the_transfer_and_level_forms_validate_their_input_instead_of_failing(): void
    {
        $program = $this->program();
        $one = $this->level($program, 'Level I');
        $enrollment = $this->admit($program, $this->client(), $one);
        $base = ['program' => $program->id, 'enrollment' => $enrollment->id];

        $this->as($this->admin)->post($this->url('app.programs.enrollments.transfer', $base), [])->assertSessionHasErrors('program_id');
        $this->post($this->url('app.programs.enrollments.level', $base), ['level_id' => (string) Str::uuid()])->assertSessionHasErrors('reason');
        $this->post($this->url('app.programs.enrollments.discharge', $base), ['outcome' => 'banana'])->assertSessionHasErrors('outcome');
        $this->assertSame('active', $enrollment->fresh()->status->value);
        $this->assertCount(1, $this->events($enrollment));
    }

    #[Test]
    public function repeating_a_transition_is_a_no_op_with_no_second_history_row_or_audit_entry(): void
    {
        $enrollment = $this->admit($this->program(), $this->client());

        $this->doAs(fn () => app(PutEnrollmentOnHold::class)($enrollment, 'travelling'));
        $this->doAs(fn () => app(PutEnrollmentOnHold::class)($enrollment, 'travelling'));   // a double click
        $this->assertCount(2, $this->events($enrollment));
        $this->assertCount(1, $this->auditEntries('program_enrollment.put_on_hold'));

        $this->doAs(fn () => app(ResumeEnrollment::class)($enrollment));
        $this->doAs(fn () => app(ResumeEnrollment::class)($enrollment));
        $this->assertCount(3, $this->events($enrollment));
        $this->assertSame('active', $enrollment->fresh()->status->value);
    }
}
