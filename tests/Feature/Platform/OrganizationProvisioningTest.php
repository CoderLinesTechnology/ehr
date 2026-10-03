<?php

namespace Tests\Feature\Platform;

use App\Domain\Identity\InvitationTokens;
use App\Domain\Identity\MembershipStatus;
use App\Domain\Platform\OrganizationStatus;
use App\Domain\Platform\ProvisionOrganization;
use App\Domain\Platform\UpdateOrganizationProfile;
use App\Domain\Saas\SubscriptionStatus;
use App\Domain\Shared\DomainException;
use App\Domain\Tenancy\TenantContext;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationStatusHistory;
use App\Models\Plan;
use App\Models\Subscription;
use App\Notifications\StaffInvitationNotification;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class OrganizationProvisioningTest extends PlatformTestCase
{
    /** @return array<string, string> */
    private function profile(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Riverside Counselling',
            'country_code' => 'GH',
            'timezone' => 'Africa/Accra',
            'currency' => 'GHS',
            'email' => 'hello@riverside.example',
            'phone' => '+233244000111',
        ];
    }

    private function provision(array $profile = [], string $plan = 'starter', OrganizationStatus $status = OrganizationStatus::Trial, string $ownerEmail = 'Owner@Riverside.Example')
    {
        return app(ProvisionOrganization::class)(
            $this->profile($profile),
            Plan::query()->where('key', $plan)->firstOrFail(),
            $status,
            $ownerEmail,
            auth()->user(),
        );
    }

    // ── ProvisionOrganization ───────────────────────────────────────────

    #[Test]
    public function an_organization_is_created_with_an_invited_owner_a_subscription_history_and_an_audit_entry(): void
    {
        $admin = $this->signInAsPlatform();
        Notification::fake();

        $result = $this->provision();
        $organization = $result->created->organization->fresh();

        $this->assertSame('riverside-counselling', $organization->slug);
        $this->assertSame(OrganizationStatus::Trial, $organization->status);
        $this->assertSame($admin->id, $organization->created_by_user_id);
        $this->assertSame('GH', $organization->country_code);

        // The owner is invited by email: no account is created and nobody is a member yet.
        $membership = app(TenantContext::class)->bypass(fn () => OrganizationMembership::query()->where('organization_id', $organization->id)->sole());
        $this->assertSame(MembershipStatus::Invited, $membership->status);
        $this->assertSame('owner@riverside.example', $membership->invited_email, 'The address is stored lower-cased.');
        $this->assertNull($membership->user_id);
        $this->assertSame($admin->id, $membership->invited_by_user_id);
        $this->assertTrue($membership->invitation_expires_at->isFuture());
        $this->assertSame(1, DB::table('membership_roles')->where('membership_id', $membership->id)->count(), 'The invitation carries the administrator role.');

        $subscription = Subscription::query()->where('organization_id', $organization->id)->sole();
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status, 'A trial organization starts on a trial.');
        $this->assertSame(25000, $subscription->price_minor);
        $this->assertSame('started', DB::table('subscription_histories')->where('organization_id', $organization->id)->value('event'));

        $history = OrganizationStatusHistory::query()->where('organization_id', $organization->id)->sole();
        $this->assertNull($history->from_status);
        $this->assertSame(OrganizationStatus::Trial, $history->to_status);
        $this->assertSame($admin->id, $history->actor_user_id);

        $audit = $this->audit('organization.created');
        $this->assertSame('platform', $audit->context);
        $this->assertSame($organization->id, $audit->organization_id);
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertSame('trial', $audit->after['status']);
        $this->assertSame('starter', $audit->after['plan']);
    }

    #[Test]
    public function the_owner_is_emailed_the_invitation_and_the_token_is_never_stored_or_audited(): void
    {
        $admin = $this->signInAsPlatform();
        Notification::fake();

        $result = $this->provision();
        $token = $result->created->invitationToken;

        $this->assertTrue($result->invitationSent);
        $this->assertNotNull($token);
        Notification::assertSentOnDemand(
            StaffInvitationNotification::class,
            function (StaffInvitationNotification $notification, array $channels, object $notifiable) use ($admin, $token) {
                $this->assertSame(['mail'], $channels);
                $this->assertSame('owner@riverside.example', $notifiable->routes['mail']);
                $this->assertSame('Riverside Counselling', $notification->organizationName);
                $this->assertSame($admin->name, $notification->inviterName);
                $this->assertSame(InvitationTokens::VALID_DAYS, $notification->validDays);
                $this->assertStringContainsString('/invitations/', $notification->acceptUrl);
                $this->assertStringEndsWith($token, $notification->acceptUrl);

                return true;
            },
        );

        // Only the hash is in the database; the plain token appears nowhere.
        $membership = app(TenantContext::class)->bypass(fn () => OrganizationMembership::query()->where('organization_id', $result->created->organization->id)->sole());
        $this->assertSame(InvitationTokens::hash($token), $membership->invitation_token_hash);
        $this->assertNotSame($token, $membership->invitation_token_hash);

        $everything = json_encode(AuditLog::query()->get()->toArray()).json_encode(DB::table('organization_memberships')->get()->all())
            .json_encode(DB::table('organization_status_histories')->get()->all()).json_encode(DB::table('subscription_histories')->get()->all());
        $this->assertStringNotContainsString($token, $everything);
    }

    #[Test]
    public function the_invitation_is_sent_at_once_and_never_through_the_queue(): void
    {
        // The link is a 7-day credential: a queued notification would keep it in plain text in the jobs table.
        $this->signInAsPlatform();
        Queue::fake();

        $result = $this->provision(ownerEmail: 'Owner@Riverside.Example');

        Queue::assertNothingPushed();
        $this->assertTrue($result->invitationSent);

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages->first()->getOriginalMessage();
        $this->assertSame('owner@riverside.example', $email->getTo()[0]->getAddress());
        $this->assertStringContainsString('/invitations/'.$result->created->invitationToken, $email->getTextBody().$email->getHtmlBody());
    }

    #[Test]
    public function an_active_organization_starts_an_active_subscription(): void
    {
        $this->signInAsPlatform();
        Notification::fake();

        $result = $this->provision(plan: 'professional', status: OrganizationStatus::Active);

        $this->assertSame(OrganizationStatus::Active, $result->created->organization->fresh()->status);
        $this->assertSame(SubscriptionStatus::Active, $result->created->subscription->status);
        $this->assertSame(60000, $result->created->subscription->price_minor);
    }

    #[Test]
    public function an_organization_cannot_start_suspended_archived_or_cancelled(): void
    {
        $this->signInAsPlatform();
        Notification::fake();
        $before = Organization::query()->count();

        foreach ([OrganizationStatus::Suspended, OrganizationStatus::Archived, OrganizationStatus::Cancelled] as $status) {
            try {
                $this->provision(status: $status);
                $this->fail("{$status->value} must be refused.");
            } catch (DomainException $e) {
                $this->assertSame('invalid_status', $e->errorCode());
            }
        }

        $this->assertSame($before, Organization::query()->count());
        Notification::assertNothingSent();
    }

    #[Test]
    public function a_taken_or_reserved_address_is_refused_and_nothing_is_created_or_emailed(): void
    {
        $this->signInAsPlatform();
        Notification::fake();
        $this->provision(['slug' => 'riverside']);
        $before = Organization::query()->count();

        foreach ([
            'taken' => ['riverside', 'slug_taken'],
            'reserved' => ['platform', 'slug_taken'],
            'not a slug' => ['Not A Slug!', 'invalid_slug'],
        ] as $name => [$slug, $code]) {
            try {
                $this->provision(['name' => 'Another Practice', 'slug' => $slug]);
                $this->fail("{$name}: must be refused.");
            } catch (DomainException $e) {
                $this->assertSame($code, $e->errorCode(), $name);
                $this->assertSame('slug', $e->field());
            }
        }

        $this->assertSame($before, Organization::query()->count());
        Notification::assertSentOnDemandTimes(StaffInvitationNotification::class, 1);
    }

    #[Test]
    public function when_the_email_cannot_be_handed_over_the_organization_still_exists_and_the_failure_is_reported(): void
    {
        $this->signInAsPlatform();
        $this->app->instance(Dispatcher::class, new class implements Dispatcher
        {
            public function send($notifiables, $notification): void
            {
                throw new RuntimeException('mail transport down');
            }

            public function sendNow($notifiables, $notification, ?array $channels = null): void
            {
                throw new RuntimeException('mail transport down');
            }
        });
        $handler = \Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once();
        $this->app->instance(ExceptionHandler::class, $handler);

        $result = $this->provision();

        $this->assertFalse($result->invitationSent, 'The caller is told so it can warn the administrator.');
        $this->assertSame(1, Organization::query()->where('slug', 'riverside-counselling')->count(), 'The organization was created and audited.');
        $this->assertSame(1, $this->auditCount('organization.created'));
    }

    // ── UpdateOrganizationProfile ───────────────────────────────────────

    #[Test]
    public function editing_the_profile_saves_it_and_audits_the_old_and_new_values_of_what_changed(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(['name' => 'Old Name', 'email' => 'old@example.org'])->organization;

        $returned = app(UpdateOrganizationProfile::class)($organization, [
            'name' => 'New Name', 'email' => 'new@example.org', 'phone' => '+233200000000', 'city' => 'Accra',
            'country_code' => 'gh', 'currency' => 'ghs', 'timezone' => 'Africa/Accra',
        ]);

        $fresh = $organization->fresh();
        $this->assertSame('New Name', $fresh->name);
        $this->assertSame('new@example.org', $fresh->email);
        $this->assertSame('Accra', $fresh->city);
        $this->assertSame('GH', $fresh->country_code, 'Codes are upper-cased.');
        $this->assertSame('GHS', $fresh->currency);
        $this->assertSame('New Name', $returned->name, 'The caller\'s instance is brought up to date.');

        $audit = $this->audit('organization.updated');
        $this->assertSame('platform', $audit->context, 'Filed under the platform even without an HTTP route.');
        $this->assertSame($organization->id, $audit->subject_id);
        $this->assertSame($organization->id, $audit->organization_id);
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertSame('Old Name', $audit->before['name']);
        $this->assertSame('New Name', $audit->after['name']);
        $this->assertSame('old@example.org', $audit->before['email']);
        $this->assertNull($audit->before['phone']);
        $this->assertSame('+233200000000', $audit->after['phone']);
        $this->assertArrayNotHasKey('timezone', $audit->after, 'Unchanged columns are not repeated.');
        $this->assertArrayNotHasKey('country_code', $audit->after, 'Upper-casing "gh" to "GH" is not a change.');
    }

    #[Test]
    public function editing_the_profile_cannot_change_the_address_the_status_or_anything_that_is_not_profile(): void
    {
        $this->signInAsPlatform();
        $organization = $this->createOrganization(['name' => 'Fixed'])->organization;
        $slug = $organization->slug;

        app(UpdateOrganizationProfile::class)($organization, [
            'name' => 'Fixed Too', 'slug' => 'hijacked', 'status' => 'archived', 'status_reason' => 'x', 'custom_domain' => 'evil.example',
            'created_by_user_id' => 'nobody', 'id' => 'other',
        ]);

        $fresh = $organization->fresh();
        $this->assertSame('Fixed Too', $fresh->name);
        $this->assertSame($slug, $fresh->slug);
        $this->assertSame(OrganizationStatus::Active, $fresh->status);
        $this->assertNull($fresh->custom_domain);
        $this->assertSame($organization->id, $fresh->id);

        $audit = $this->audit('organization.updated');
        $this->assertSame(['name'], array_keys($audit->after));
    }

    #[Test]
    public function saving_an_unchanged_profile_writes_no_audit_entry(): void
    {
        $this->signInAsPlatform();
        $organization = $this->createOrganization(['name' => 'Same'])->organization;

        app(UpdateOrganizationProfile::class)($organization, ['name' => 'Same', 'country_code' => 'GH']);

        $this->assertSame(0, $this->auditCount('organization.updated'));
    }
}
