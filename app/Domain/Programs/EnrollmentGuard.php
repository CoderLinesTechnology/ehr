<?php

namespace App\Domain\Programs;

use App\Domain\Identity\AccessGuard;
use App\Domain\Identity\PermissionResolver;
use App\Domain\Shared\DomainException;
use App\Models\LevelOfCare;
use App\Models\OrganizationMembership;
use App\Models\Program;
use App\Models\ProgramEnrollment;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** The checks every enrollment action makes before it writes (one place, so they cannot drift apart). */
final class EnrollmentGuard
{
    public function __construct(private readonly AccessGuard $guard, private readonly PermissionResolver $permissions) {}

    /**
     * The actor may touch this program's enrollments: they hold programs.enroll, and programs.view_sud for a
     * flagged program. A refusal is a plain "forbidden", not a hint that the program is segmented.
     *
     * @throws DomainException
     */
    public function assertCanEnrollIn(Program $program): void
    {
        $this->guard->requirePermission('programs.enroll');
        if ($program->is_sud_program) {
            $this->guard->requirePermission('programs.view_sud');
        }
    }

    /**
     * The enrollment is one the actor may see (segmentation and client visibility): anything else does not exist for them.
     *
     * @throws ModelNotFoundException
     */
    public function assertCanSee(ProgramEnrollment $enrollment): void
    {
        if (! ProgramVisibility::allowsEnrollment($enrollment, $this->guard->actor())) {
            throw (new ModelNotFoundException)->setModel(ProgramEnrollment::class, [$enrollment->getKey()]);
        }
    }

    /** @throws DomainException */
    public function level(Program $program, ?LevelOfCare $level, string $field = 'level_id'): ?LevelOfCare
    {
        $hasLevels = LevelOfCare::query()->where('program_id', $program->id)->where('is_active', true)->exists();

        if ($level === null) {
            return $hasLevels ? throw new DomainException('Choose a level of care.', 'level_required', $field) : null;
        }

        $this->guard->assertInOrganization($level);
        if ($level->program_id !== $program->id || ! $level->is_active) {
            throw new DomainException('Choose one of this program’s active levels of care.', 'invalid_level', $field);
        }

        return $level;
    }

    /** The member who authorised a level change: an active member who may admit and transition clients. @throws DomainException */
    public function authorizer(?OrganizationMembership $authorizedBy, string $field = 'authorized_by'): OrganizationMembership
    {
        $authorizedBy ??= $this->guard->actor();
        $this->guard->assertInOrganization($authorizedBy);

        if (! $authorizedBy->isActive() || ! $this->permissions->membershipHas($authorizedBy, 'programs.enroll')) {
            throw new DomainException('The authorizing person must be an active team member who can manage admissions.', 'invalid_authorizer', $field);
        }

        return $authorizedBy;
    }
}
