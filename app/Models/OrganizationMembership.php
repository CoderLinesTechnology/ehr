<?php

namespace App\Models;

use App\Domain\Identity\MembershipStatus;
use App\Domain\Tenancy\BelongsToOrganization;
use App\Domain\Tenancy\ScopesTenantPivots;
use Database\Factories\OrganizationMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A user's place in one organization (staff seat). Status, invitation and
 * role assignment are written by the Identity domain actions only.
 */
#[Fillable(['name_prefix', 'title', 'credentials', 'is_provider', 'color'])]
class OrganizationMembership extends Model
{
    /** @use HasFactory<OrganizationMembershipFactory> */
    use BelongsToOrganization, ScopesTenantPivots, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'is_provider' => 'boolean',
            'invitation_expires_at' => 'immutable_datetime',
            'joined_at' => 'immutable_datetime',
            'deactivated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->tenantPivot($this->belongsToMany(Role::class, 'membership_roles', 'membership_id', 'role_id'));
    }

    /** @return BelongsToMany<Service, $this> */
    public function services(): BelongsToMany
    {
        return $this->tenantPivot($this->belongsToMany(Service::class, 'service_providers', 'membership_id', 'service_id'));
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    /** Name for display; invitations have no user yet. */
    public function displayName(): string
    {
        if ($this->user_id === null) {
            return (string) $this->invited_email;
        }

        $user = $this->relationLoaded('user') ? $this->user : $this->user()->first();

        return $user?->name ?? '—';
    }

    /** "Dr. Sarah Carter" — the name with its honorific, as shown on schedules. */
    public function professionalName(): string
    {
        return trim(($this->name_prefix ? $this->name_prefix.' ' : '').$this->displayName());
    }

    /** Active staff who can be booked for appointments. */
    public function scopeProviders(Builder $query): Builder
    {
        return $query->where('status', MembershipStatus::Active->value)->where('is_provider', true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', MembershipStatus::Active->value);
    }
}
