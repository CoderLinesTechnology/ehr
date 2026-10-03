<?php

namespace Tests\Feature\Programs;

use App\Domain\Programs\AssignProgramStaff;
use App\Domain\Programs\ChangeProgramStatus;
use App\Domain\Programs\ProgramStatus;
use App\Domain\Programs\SaveLevelOfCare;
use App\Domain\Programs\SaveProgram;
use App\Domain\Programs\StaffRole;
use App\Domain\Saas\EntitlementService;
use App\Domain\Saas\FeatureRegistry;
use App\Domain\Shared\DomainException;
use App\Models\OrganizationEntitlement;
use App\Models\OrganizationMembership;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/** Program rules: status machine, plan limit, levels, staff, segmentation flag. */
class ProgramLifecycleTest extends ProgramsTestCase
{
    /** The allowed moves, spelled out by hand (independent of ProgramStatus::next()). */
    public static function moves(): array
    {
        $all = ['upcoming', 'active', 'on_hold', 'completed', 'archived'];
        $allowed = [
            'upcoming' => ['active', 'archived'],
            'active' => ['on_hold', 'completed'],
            'on_hold' => ['active', 'completed', 'archived'],
            'completed' => ['archived'],
            'archived' => [],
        ];
        $cases = [];
        foreach ($all as $from) {
            foreach ($all as $to) {
                if ($from !== $to) {
                    $cases["{$from} -> {$to}"] = [$from, $to, in_array($to, $allowed[$from], true)];
                }
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('moves')]
    public function the_status_machine_allows_exactly_the_documented_moves(string $from, string $to, bool $allowed): void
    {
        $program = $this->program(status: ProgramStatus::Upcoming);
        DB::table('programs')->where('id', $program->id)->update(['status' => $from]);
        $program = $program->fresh();

        if (! $allowed) {
            $this->expectException(DomainException::class);
        }
        $result = $this->doAs(fn () => app(ChangeProgramStatus::class)($program, ProgramStatus::from($to)));

        $this->assertSame($to, $result->status->value);
        $this->assertSame($to, DB::table('programs')->where('id', $program->id)->value('status'));
    }

    #[Test]
    public function asking_for_the_status_it_already_has_changes_nothing(): void
    {
        $program = $this->program();
        $before = count($this->auditEntries('program.status_changed'));

        $this->doAs(fn () => app(ChangeProgramStatus::class)($program, ProgramStatus::Active));

        $this->assertCount($before, $this->auditEntries('program.status_changed'));
    }

    #[Test]
    public function a_program_with_open_enrollments_cannot_be_completed_or_archived(): void
    {
        $program = $this->program();
        $enrollment = $this->admit($program, $this->client());

        try {
            $this->doAs(fn () => app(ChangeProgramStatus::class)($program, ProgramStatus::Completed));
            $this->fail('expected a refusal');
        } catch (DomainException $e) {
            $this->assertSame('open_enrollments', $e->errorCode());
        }

        DB::table('program_enrollments')->where('id', $enrollment->id)->update(['status' => 'discharged', 'ended_at' => now()]);
        $this->doAs(fn () => app(ChangeProgramStatus::class)($program, ProgramStatus::Completed));
        $this->assertSame('completed', $program->fresh()->status->value);
    }

    #[Test]
    public function max_programs_limits_open_programs_on_create_and_on_reopening(): void
    {
        $this->limitProgramsTo(2);

        $one = $this->program();
        $two = $this->program(status: ProgramStatus::Upcoming);

        try {
            $this->program();
            $this->fail('the third open program must be refused');
        } catch (DomainException $e) {
            $this->assertSame('limit_reached', $e->errorCode());
        }
        $this->assertSame(2, DB::table('programs')->where('organization_id', $this->a->organization->id)->count());

        // A completed program frees its place; the next one can be created.
        $this->doAs(fn () => app(ChangeProgramStatus::class)($one, ProgramStatus::Completed));
        $three = $this->program();
        $this->assertSame('active', $three->status->value);

        // Reaching the limit again: a completed program cannot be brought back (completed -> active is not a move at all,
        // archived is terminal), and archiving frees nothing more.
        $this->expectException(DomainException::class);
        $this->program();
    }

    #[Test]
    public function the_limit_is_per_organization(): void
    {
        $this->limitProgramsTo(1);
        $this->program();
        $other = $this->program(in: $this->b);

        $this->assertSame($this->b->organization->id, $other->organization_id);
    }

    #[Test]
    public function level_names_are_unique_within_a_program_and_not_across_programs(): void
    {
        $one = $this->program();
        $two = $this->program();
        $this->level($one, 'Level I');
        $this->level($two, 'Level I');

        try {
            $this->level($one, 'level i');
            $this->fail('duplicate');
        } catch (DomainException $e) {
            $this->assertSame('duplicate_level', $e->errorCode());
        }
    }

    #[Test]
    public function a_level_with_enrolled_participants_cannot_be_turned_off(): void
    {
        $program = $this->program();
        $level = $this->level($program);
        $this->admit($program, $this->client(), $level);

        try {
            $this->doAs(fn () => app(SaveLevelOfCare::class)($program, ['name' => $level->name, 'is_active' => false], $level));
            $this->fail('level in use');
        } catch (DomainException $e) {
            $this->assertSame('level_in_use', $e->errorCode());
        }
    }

    #[Test]
    public function staff_are_assigned_with_a_role_and_only_active_members_can_join(): void
    {
        $program = $this->program();

        $this->doAs(fn () => app(AssignProgramStaff::class)($program, $this->clinician, StaffRole::Director));
        $this->doAs(fn () => app(AssignProgramStaff::class)($program, $this->clinician, StaffRole::Clinician)); // role change, same row
        $this->assertSame(1, DB::table('program_staff')->where('program_id', $program->id)->count());
        $this->assertSame('clinician', DB::table('program_staff')->where('program_id', $program->id)->value('role'));

        DB::table('organization_memberships')->where('id', $this->none->id)->update(['status' => 'suspended']);
        $this->expectException(DomainException::class);
        $this->doAs(fn () => app(AssignProgramStaff::class)($program, $this->none->fresh(), StaffRole::Other));
    }

    #[Test]
    public function the_sud_flag_can_only_be_set_or_changed_by_someone_who_may_view_sud_records(): void
    {
        // The manager holds programs.manage but not programs.view_sud.
        try {
            $this->doAs(fn () => app(SaveProgram::class)(['name' => 'Recovery', 'color' => 'green', 'starts_on' => '2026-01-01', 'is_sud_program' => true]), $this->manager);
            $this->fail('manager cannot flag');
        } catch (DomainException $e) {
            $this->assertSame('forbidden', $e->errorCode());
        }

        $program = $this->program(sud: true);
        $this->assertTrue($program->is_sud_program);

        // ... nor edit a flagged program, nor change its status or levels.
        foreach ([
            fn () => app(SaveProgram::class)(['name' => 'Renamed', 'color' => 'green', 'starts_on' => '2026-01-01', 'is_sud_program' => true], $program),
            fn () => app(ChangeProgramStatus::class)($program, ProgramStatus::OnHold),
            fn () => app(SaveLevelOfCare::class)($program, ['name' => 'Level X']),
        ] as $attempt) {
            try {
                $this->doAs($attempt, $this->manager);
                $this->fail('refused');
            } catch (DomainException $e) {
                $this->assertSame('forbidden', $e->errorCode());
            }
        }

        // The supervisor holds view_sud and may un-flag it (audited with before/after).
        $this->doAs(fn () => app(SaveProgram::class)(['name' => $program->name, 'color' => 'green', 'starts_on' => '2026-01-01', 'is_sud_program' => false], $program), $this->supervisor_with_manage());
        $this->assertFalse($program->fresh()->is_sud_program);
    }

    #[Test]
    public function programs_audit_without_description_text(): void
    {
        $program = $this->program(['description' => 'private-description-text']);
        $this->doAs(fn () => app(SaveProgram::class)(['name' => $program->name, 'color' => 'blue', 'starts_on' => '2026-01-01', 'description' => 'another private text'], $program));

        $audit = DB::table('audit_logs')->whereIn('action', ['program.created', 'program.updated'])->get()->map(fn ($r) => json_encode($r))->implode(' ');
        $this->assertStringNotContainsString('private-description-text', $audit);
        $this->assertStringNotContainsString('another private text', $audit);
        $this->assertCount(1, $this->auditEntries('program.updated'));
    }

    /** A member holding both programs.manage and programs.view_sud (no template has both): the administrator. */
    private function supervisor_with_manage(): OrganizationMembership
    {
        return $this->admin;
    }

    private function limitProgramsTo(int $limit): void
    {
        OrganizationEntitlement::query()->updateOrCreate(
            ['organization_id' => $this->a->organization->id, 'feature_key' => FeatureRegistry::MAX_PROGRAMS],
            ['limit_value' => $limit, 'reason' => 'test'],
        );
        app(EntitlementService::class)->flush();
    }
}
