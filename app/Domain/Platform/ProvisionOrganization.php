<?php

namespace App\Domain\Platform;

use App\Domain\Identity\InvitationTokens;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\StaffInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * A platform administrator creates an organization for a customer: the
 * organization, its subscription and an invited owner are created (all or
 * nothing, by CreateOrganization), then the owner is emailed their invitation.
 * The email is sent after the transaction has committed, immediately rather than
 * through the queue, and the plain token is used only to build the link: it is
 * never stored, logged, audited or put in a queue payload.
 */
final class ProvisionOrganization
{
    public function __construct(private readonly CreateOrganization $createOrganization) {}

    /**
     * @param  array{name: string, slug?: ?string, country_code: string, timezone: string, currency: string,
     *               locale?: ?string, email?: ?string, phone?: ?string, legal_name?: ?string}  $profile
     */
    public function __invoke(array $profile, Plan $plan, OrganizationStatus $status, string $ownerEmail, User $actor): ProvisionedOrganization
    {
        $created = ($this->createOrganization)(
            profile: $profile,
            plan: $plan,
            status: $status,
            ownerEmail: $ownerEmail,
            actor: $actor,
        );

        return new ProvisionedOrganization($created, $this->sendInvitation($created, $ownerEmail, $actor));
    }

    private function sendInvitation(CreatedOrganization $created, string $ownerEmail, User $actor): bool
    {
        $token = $created->invitationToken;
        if ($token === null) {
            return false;
        }

        $url = Route::has('invitations.show')
            ? route('invitations.show', $token)
            : url('/invitations/'.$token);

        try {
            // Sent now, not queued: the link IS the credential (a 7-day token). A queued notification is serialised into
            // the jobs table (and failed_jobs, indefinitely, if delivery fails) with the link in plain text. Sending
            // inline also tells the administrator at once when the mail system refuses the message.
            Notification::route('mail', mb_strtolower(trim($ownerEmail)))->notifyNow(new StaffInvitationNotification(
                $created->organization->name,
                $actor->name,
                $url,
                InvitationTokens::VALID_DAYS,
            ));
        } catch (Throwable $e) {
            // The organization exists and is audited; surface the failure to the administrator.
            report($e);

            return false;
        }

        return true;
    }
}
