<?php

namespace App\Models;

use App\Domain\Programs\EnrollmentEventType;
use App\Domain\Programs\EnrollmentStatus;
use App\Domain\Shared\InsertOnly;
use App\Domain\Shared\RecordEnvironment;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One fact in an enrollment's history. Insert-only (model guard + database trigger): corrections are new events. */
#[Table(timestamps: false)]
class ProgramEnrollmentEvent extends Model
{
    use BelongsToOrganization, HasUuids, InsertOnly;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return [
            'record_environment' => RecordEnvironment::class,
            'event_type' => EnrollmentEventType::class,
            'from_status' => EnrollmentStatus::class,
            'to_status' => EnrollmentStatus::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<LevelOfCare, $this> */
    public function fromLevel(): BelongsTo
    {
        return $this->belongsTo(LevelOfCare::class, 'from_level_id');
    }

    /** @return BelongsTo<LevelOfCare, $this> */
    public function toLevel(): BelongsTo
    {
        return $this->belongsTo(LevelOfCare::class, 'to_level_id');
    }

    /** @return BelongsTo<OrganizationMembership, $this> */
    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(OrganizationMembership::class, 'authorized_by_membership_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
