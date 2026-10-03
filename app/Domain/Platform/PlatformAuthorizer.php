<?php

namespace App\Domain\Platform;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * Answers "may this person do this in the console?" for every caller the same
 * way. The answer is: they hold the platform.* permission the ability needs
 * (through a platform role, resolved by the application's Gate), their account
 * is enabled, and their two-factor authentication is confirmed. The last two
 * mirror EnsurePlatformAccess, so a call that never passes through that
 * middleware (a command, a job, another adapter) is held to the same bar.
 *
 * It decides nothing about a record: rules such as "not your own account" or
 * "the last Super Admin" belong to the domain actions.
 *
 * Known limit, shared with EnsurePlatformAccess: "two-factor confirmed" is a fact
 * about the ACCOUNT, not proof that THIS session presented the second factor
 * (a session opened with the password alone before enrolment still passes).
 * Closing that needs a session marker written by the challenge and enrolment
 * flows and checked by the gate; when it exists, check the same marker here.
 * Until then GrantPlatformRole ends the person's existing sessions on grant.
 */
final class PlatformAuthorizer
{
    public function __construct(private readonly Gate $gate) {}

    public function can(?User $user, PlatformAbility $ability): bool
    {
        return $user !== null
            && ! $user->isDisabled()
            && $user->hasConfirmedTwoFactor()
            && $this->gate->forUser($user)->allows($ability->permission());
    }

    /** @throws AuthorizationException (renders as HTTP 403) */
    public function authorize(?User $user, PlatformAbility $ability): void
    {
        if (! $this->can($user, $ability)) {
            throw new AuthorizationException('You do not have permission to do that.');
        }
    }

    /**
     * Every ability the person holds, for building navigation and action menus.
     * Hiding what someone cannot do is cosmetic: every action still authorizes.
     *
     * @return list<PlatformAbility>
     */
    public function abilities(?User $user): array
    {
        return array_values(array_filter(PlatformAbility::cases(), fn (PlatformAbility $ability) => $this->can($user, $ability)));
    }
}
