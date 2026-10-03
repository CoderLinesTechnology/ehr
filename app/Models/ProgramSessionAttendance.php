<?php

namespace App\Models;

use App\Domain\Programs\AttendanceStatus;
use App\Domain\Shared\RecordEnvironment;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgramSessionAttendance extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $table = 'program_session_attendance';

    protected function casts(): array
    {
        return ['status' => AttendanceStatus::class, 'record_environment' => RecordEnvironment::class];
    }

    /** @return BelongsTo<ProgramEnrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(ProgramEnrollment::class, 'enrollment_id');
    }
}
