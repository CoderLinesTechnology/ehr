<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Events\MembershipInvited;
use App\Domain\Shared\DomainException;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Invites someone by email to join the current organization as staff.
 *
 * The invitation is a membership row in status `invited` (no user yet) carrying the
 * SHA-256 of a 64-character token; the token itself only travels in the email.
 * Counts against `max_staff`. One pending invitation per email (also a database
 * unique index). The roles offered are bounded by what the inviter may grant.
 */
final class InviteStaff
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly StaffSeats $seats,
        private readonly AuditLogger $audit,
        private readonly SendInvitation $send,
    ) {}

    /**
     * @param  list<string>  $roleIds
     *
     * @throws DomainException
     */
    public function __invoke(
        string $email,
        array $roleIds,
        ?string $title = null,
        bool $isProvider = false,
        ?string $credentials = null,
    ): OrganizationMembership {
        $this->guard->requirePermission('team.manage');

        $organization = $this->guard->organization();
        $actor = $this->guard->actor();

        $email = mb_strtolower(trim($email));
        if (mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('Enter a valid email address.', 'invalid_email', 'email');
        }

        $roles = $this->guard->resolveRoles($roleIds);
        if ($roles->isEmpty()) {
            throw new DomainException('Choose at least one role.', 'no_roles', 'roles');
        }
        $this->guard->assertCanManageRoles($roles);

        $token = InvitationTokens::generate();
        $inviterName = User::query()->whereKey($actor->user_id)->value('name');

        try {
            $invite = DB::transaction(function () use ($email, $roles, $title, $isProvider, $credentials, $token, $organization, $actor) {
                // Serialises invitations, seat counting and access changes for this organization.
                $this->guard->lockOrganization();

                $this->assertNotAlreadyOnTheTeam($email);
                $this->seats->assertAvailable($organization);

                $invite = new OrganizationMembership;
                $invite->fill([
                    'title' => $this->clean($title),
                    'credentials' => $this->clean($credentials),
                    'is_provider' => $isProvider,
                ]);
                $invite->forceFill([
                    'status' => MembershipStatus::Invited,
                    'invited_email' => $email,
                    'invitation_token_hash' => InvitationTokens::hash($token),
                    'invitation_expires_at' => now()->addDays(InvitationTokens::VALID_DAYS),
                    'invited_by_user_id' => $actor->user_id,
                ])->save();

                $invite->roles()->attach($roles->pluck('id')->all());

                $this->audit->record(
                    'team.member_invited',
                    $invite,
                    after: [
                        'email' => $email,
                        'roles' => $roles->pluck('name')->sort()->values()->all(),
                        'title' => $invite->title,
                        'is_provider' => $isProvider,
                    ],
                    summary: "Invited {$email} to the team",
                );

                MembershipInvited::dispatch($organization->id, $invite->id, $actor->user_id);

                return $invite;
            });
        } catch (UniqueConstraintViolationException) {
            // Two invitations raced past the checks; the database index decided.
            throw new DomainException("An invitation to {$email} is already pending.", 'already_invited', 'email');
        }

        ($this->send)($organization, $invite, $token, $inviterName);

        return $invite;
    }

    private function assertNotAlreadyOnTheTeam(string $email): void
    {
        $userId = User::query()->whereRaw('lower(email) = ?', [$email])->value('id');

        if ($userId !== null) {
            $existing = OrganizationMembership::query()->where('user_id', $userId)->first(['id', 'status']);

            if ($existing !== null) {
                throw match ($existing->status) {
                    MembershipStatus::Active => new DomainException("{$email} is already on your team.", 'already_member', 'email'),
                    default => new DomainException(
                        "{$email} already has a {$existing->status->value} membership here. Reactivate them from the team list instead of sending a new invitation.",
                        'already_member',
                        'email',
                    ),
                };
            }
        }

        $pending = OrganizationMembership::query()
            ->where('status', MembershipStatus::Invited->value)
            ->whereRaw('lower(invited_email) = ?', [$email])
            ->exists();

        if ($pending) {
            throw new DomainException(
                "An invitation to {$email} is already pending. Resend or revoke it from the team list.",
                'already_invited',
                'email',
            );
        }
    }

    private function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
