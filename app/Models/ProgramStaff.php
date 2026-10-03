<?php

namespace App\Models;

use App\Domain\Programs\StaffRole;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgramStaff extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $table = 'program_staff';

    protected function casts(): array
    {
        return ['role' => StaffRole::class];
    }

    /** @return BelongsTo<OrganizationMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(OrganizationMembership::class, 'membership_id');
    }

    /** @return BelongsTo<Program, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
