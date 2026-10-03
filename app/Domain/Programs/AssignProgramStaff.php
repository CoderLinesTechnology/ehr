<?php

namespace App\Domain\Programs;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\AccessGuard;
use App\Domain\Shared\DomainException;
use App\Models\OrganizationMembership;
use App\Models\Program;
use App\Models\ProgramStaff;
use Illuminate\Support\Facades\DB;

/**
 * Puts a member of the organization on a program's staff with a program role (director, clinician, supervisor,
 * coordinator, other), or changes the role they already have. Needs `programs.manage`; only active members can be
 * assigned. Audit: `program.staff_assigned`.
 */
final class AssignProgramStaff
{
    public function __construct(private readonly AccessGuard $guard, private readonly AuditLogger $audit) {}

    /** @throws DomainException */
    public function __invoke(Program $program, OrganizationMembership $membership, StaffRole $role): ProgramStaff
    {
        $this->guard->requirePermission('programs.manage');
        $this->guard->assertInOrganization($program);
        $this->guard->assertInOrganization($membership);

        if (! $membership->isActive()) {
            throw new DomainException('Only active team members can join a program’s staff.', 'inactive_member', 'membership_id');
        }

        return DB::transaction(function () use ($program, $membership, $role) {
            $locked = Program::query()->whereKey($program->id)->lockForUpdate()->firstOrFail();
            if ($locked->is_sud_program) {
                $this->guard->requirePermission('programs.view_sud');
            }

            $row = ProgramStaff::query()->where('program_id', $locked->id)->where('membership_id', $membership->id)->first() ?? new ProgramStaff;
            $before = $row->exists ? $row->role->value : null;
            $row->forceFill(['program_id' => $locked->id, 'membership_id' => $membership->id, 'role' => $role])->save();

            $name = $membership->displayName();
            $this->audit->record(
                'program.staff_assigned',
                $row,
                before: $before === null ? null : ['role' => $before],
                after: ['role' => $role->value, 'member' => $name],
                summary: "{$name} is now {$role->label()} of “{$locked->name}”",
            );

            return $row;
        });
    }
}
