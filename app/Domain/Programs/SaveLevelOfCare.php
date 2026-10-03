<?php

namespace App\Domain\Programs;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\AccessGuard;
use App\Domain\Organization\AuditDiff;
use App\Domain\Shared\DomainException;
use App\Models\LevelOfCare;
use App\Models\Program;
use App\Models\ProgramEnrollment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Creates or edits a level of care of a program (e.g. "Level I – Outpatient"). Needs `programs.manage`.
 * A level that open enrollments sit on cannot be deactivated (move them first). Names are unique within a program.
 * Audit: `level_of_care.created` / `level_of_care.updated`.
 */
final class SaveLevelOfCare
{
    public function __construct(private readonly AccessGuard $guard, private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $input  name, description, eligibility, sort, is_active
     *
     * @throws DomainException
     */
    public function __invoke(Program $program, array $input, ?LevelOfCare $level = null): LevelOfCare
    {
        $this->guard->requirePermission('programs.manage');
        $this->guard->assertInOrganization($program);
        if ($level !== null) {
            $this->guard->assertInOrganization($level);
            if ($level->program_id !== $program->id) {
                throw new DomainException('That level of care belongs to another program.', 'wrong_program');
            }
        }

        $attributes = [
            'name' => ProgramText::required($input['name'] ?? null, 80, 'name', 'level name'),
            'description' => ProgramText::optional($input['description'] ?? null, 1000, 'description', 'description'),
            'eligibility' => ProgramText::optional($input['eligibility'] ?? null, 1000, 'eligibility', 'eligibility'),
            'sort' => max(0, min(65535, (int) ($input['sort'] ?? 0))),
        ];
        $active = (bool) ($input['is_active'] ?? true);

        try {
            return DB::transaction(function () use ($program, $level, $attributes, $active) {
                $locked = Program::query()->whereKey($program->id)->lockForUpdate()->firstOrFail();
                if ($locked->is_sud_program) {
                    $this->guard->requirePermission('programs.view_sud');
                }
                $target = $level === null ? new LevelOfCare : LevelOfCare::query()->whereKey($level->id)->lockForUpdate()->firstOrFail();
                $creating = $level === null;

                if (! $creating && $target->is_active && ! $active
                    && ProgramEnrollment::query()->where('current_level_id', $target->id)->whereIn('status', EnrollmentStatus::OPEN)->exists()) {
                    throw new DomainException('Participants are on this level of care. Change their level before turning it off.', 'level_in_use', 'is_active');
                }

                $target->fill($attributes);
                $target->forceFill(['program_id' => $locked->id, 'is_active' => $active]);

                if ($creating) {
                    $target->save();
                    $this->audit->record('level_of_care.created', $target, after: ['program' => $locked->is_sud_program ? null : $locked->name, 'name' => $target->name], summary: "Added the level of care “{$target->name}”");
                } else {
                    [$before, $after] = AuditDiff::of($target, ['updated_at', 'description', 'eligibility']);
                    $textChanged = $target->isDirty('description') || $target->isDirty('eligibility');
                    $target->save();
                    if ($after !== [] || $textChanged) {
                        $this->audit->record('level_of_care.updated', $target, before: $before, after: $after, metadata: $textChanged ? ['text_changed' => true] : [], summary: "Updated the level of care “{$target->name}”");
                    }
                }

                return $target;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505' && str_contains($e->getMessage(), 'levels_of_care_name_unique')) {
                throw new DomainException('This program already has a level of care with that name.', 'duplicate_level', 'name');
            }
            throw $e;
        }
    }
}
