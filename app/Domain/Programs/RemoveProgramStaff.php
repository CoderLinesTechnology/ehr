<?php

namespace App\Domain\Programs;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\AccessGuard;
use App\Models\Program;
use App\Models\ProgramStaff;
use Illuminate\Support\Facades\DB;

/** Takes a member off a program's staff. Needs `programs.manage`. Audit: `program.staff_removed`. */
final class RemoveProgramStaff
{
    public function __construct(private readonly AccessGuard $guard, private readonly AuditLogger $audit) {}

    public function __invoke(ProgramStaff $staff): void
    {
        $this->guard->requirePermission('programs.manage');
        $this->guard->assertInOrganization($staff);

        DB::transaction(function () use ($staff) {
            $row = ProgramStaff::query()->whereKey($staff->id)->with(['membership.user:id,name', 'program:id,organization_id,name,is_sud_program'])->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            if ($row->program->is_sud_program) {
                $this->guard->requirePermission('programs.view_sud');
            }

            $name = $row->membership->displayName();
            $row->delete();
            $this->audit->record(
                'program.staff_removed',
                $row,
                before: ['role' => $row->role->value, 'member' => $name],
                summary: "{$name} left the staff of “{$row->program->name}”",
            );
        });
    }
}
