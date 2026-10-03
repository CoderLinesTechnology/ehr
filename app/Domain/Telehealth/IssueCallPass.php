<?php

namespace App\Domain\Telehealth;

use App\Domain\Identity\PermissionResolver;
use App\Domain\Scheduling\Support\TenantGuard;
use App\Domain\Shared\DomainException;
use App\Domain\Telehealth\Providers\CallPass;
use App\Domain\Telehealth\Providers\PassSpec;
use App\Domain\Telehealth\Providers\ProviderRegistry;
use App\Domain\Telehealth\Providers\VideoServiceException;
use App\Domain\Tenancy\TenantContext;
use App\Models\OrganizationMembership;
use App\Models\TelehealthSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * A staff member's pass into a running session's room, minted fresh for every call-page load and never stored,
 * logged or audited. The pass is bound to the room and expires with it (at most MAX_HOURS from now). It names the
 * staff member by their professional name and membership UUID (never client data); it makes them an owner — who
 * admits the client from the lobby — when they are the session's clinician or manage telehealth; and it carries
 * cloud recording only when the organization allows recording, the client's consent is recorded for this
 * session, and the viewer may see the session's clinical content.
 */
final class IssueCallPass
{
    public const MAX_HOURS = 4;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PrepareRoom $prepare,
        private readonly ProviderRegistry $providers,
        private readonly TelehealthSettings $settings,
        private readonly PermissionResolver $permissions,
    ) {}

    public function __invoke(TelehealthSession $session, OrganizationMembership $viewer): CallPass
    {
        $organization = $this->tenant->organizationOrFail();
        TenantGuard::assertOwned($organization->id, $session, $viewer);

        if ($session->status !== SessionStatus::InProgress) {
            throw new DomainException('Start the session before opening the call.', 'session_not_running');
        }

        $room = ($this->prepare)($session) ?? throw new DomainException('Video is not available for this session.', 'video_not_available');

        /** @var User $user */
        $user = User::query()->findOrFail($viewer->user_id);
        $roomExpires = $session->provider_room_exp ?? now();
        $expiresAt = CarbonImmutable::instance($roomExpires)->min(now()->addHours(self::MAX_HOURS));

        $spec = new PassSpec(
            expiresAt: $expiresAt,
            userName: $viewer->professionalName(),
            userId: $viewer->id,
            owner: $session->clinician_membership_id === $viewer->id || $this->permissions->membershipHas($viewer, 'telehealth.manage'),
            // The `clinical` policy decides, evaluated for THIS viewer (the policy reads the acting membership).
            record: $this->settings->recordingEnabled($organization)
                && $session->consent_to_record
                && $this->tenant->runAs($organization, fn () => Gate::forUser($user)->allows('clinical', $session), $viewer),
        );

        try {
            return $this->providers->get($session->provider_key)->issuePass($room, $spec);
        } catch (VideoServiceException) {
            throw PrepareRoom::unavailable();
        }
    }
}
