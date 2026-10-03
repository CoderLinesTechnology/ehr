<?php

namespace App\Models;

use App\Domain\Identity\MembershipStatus;
use App\Domain\Tenancy\TenantScope;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'timezone'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, TwoFactorAuthenticatable;

    // Strict mode: a freshly created user only holds the attributes that were
    // written, and actingAs() clears wasRecentlyCreated — so every nullable
    // column that framework or middleware code reads (logout → remember_token,
    // the platform 2FA gate → two_factor_*) gets an explicit default here.
    protected $attributes = [
        'remember_token' => null,
        'status' => 'active',
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
        'timezone' => null,
        'last_login_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'two_factor_confirmed_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }

    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = mb_strtolower(trim($value));
    }

    /**
     * Memberships across every organization. Cross-tenant by nature but
     * confined to this user's own rows, so the tenant scope is lifted here.
     *
     * @return HasMany<OrganizationMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class)->withoutGlobalScope(TenantScope::class);
    }

    /** @return HasMany<OrganizationMembership, $this> */
    public function activeMemberships(): HasMany
    {
        return $this->memberships()->where('status', MembershipStatus::Active->value);
    }

    /** @return BelongsToMany<Role, $this> */
    public function platformRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'platform_user_roles')
            ->withPivot(['granted_by_user_id', 'granted_at']);
    }

    public function isDisabled(): bool
    {
        return $this->status === 'disabled';
    }

    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last);
    }
}
