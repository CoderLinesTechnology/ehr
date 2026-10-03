<?php

namespace App\Domain\Identity;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Notifications\StaffInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Emails the acceptance link, after the transaction that stored the token hash has
 * committed: an email that cannot be recalled must never describe a row that might
 * still roll back. The actions call it once their own transaction is done, and
 * DB::afterCommit() holds it back further if a caller wrapped the action in a transaction
 * of its own (it runs at once when there is none). The plain token exists only in this
 * call and the mail.
 *
 * The link is built from the path, not from a route name, so the domain does not depend on
 * which controller serves the acceptance page: GET {APP_URL}/invitations/{token}.
 */
final class SendInvitation
{
    public function __invoke(Organization $organization, OrganizationMembership $invite, string $token, ?string $inviterName): void
    {
        $notification = new StaffInvitationNotification(
            organizationName: $organization->name,
            inviterName: $inviterName,
            acceptUrl: url('/invitations/'.$token),
            validDays: InvitationTokens::VALID_DAYS,
        );
        $email = (string) $invite->invited_email;

        DB::afterCommit(fn () => Notification::route('mail', $email)->notify($notification));
    }
}
