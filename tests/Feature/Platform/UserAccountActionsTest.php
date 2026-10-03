<?php

namespace Tests\Feature\Platform;

use App\Domain\Platform\Users\DisableUser;
use App\Domain\Platform\Users\EnableUser;
use App\Domain\Platform\Users\ResendVerification;
use App\Domain\Shared\DomainException;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class UserAccountActionsTest extends PlatformTestCase
{
    private function openSession(User $user): string
    {
        $id = Str::random(40);
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => 'x', 'last_activity' => time(),
        ]);

        return $id;
    }

    // ── disabling and enabling ──────────────────────────────────────────

    #[Test]
    public function disabling_an_account_ends_its_sessions_removes_its_permissions_and_is_audited(): void
    {
        $admin = $this->signInAsPlatform();
        $target = $this->platformUser('platform_support');
        $this->openSession($target);
        $this->openSession($target);
        $bystander = $this->openSession(User::factory()->create());
        $gate = Gate::forUser($target);
        $this->assertTrue($gate->allows('platform.users.view'));

        app(DisableUser::class)($target, $admin, 'Credentials were phished');

        $this->assertTrue($target->isDisabled(), 'The caller\'s instance is brought up to date.');
        $this->assertSame('disabled', $target->fresh()->status);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count(), 'The account stays signed in nowhere.');
        $this->assertSame(1, DB::table('sessions')->where('id', $bystander)->count(), 'Other people\'s sessions are untouched.');
        $this->assertFalse($gate->allows('platform.users.view'), 'A disabled account holds no permissions, even with a platform role.');

        $audit = $this->audit('user.disabled');
        $this->assertSame('platform', $audit->context);
        $this->assertSame('user', $audit->subject_type);
        $this->assertSame($target->id, $audit->subject_id);
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertSame('active', $audit->before['status']);
        $this->assertSame('disabled', $audit->after['status']);
        $this->assertSame('Credentials were phished', $audit->metadata['reason']);
    }

    #[Test]
    public function nobody_can_disable_their_own_account(): void
    {
        $admin = $this->signInAsPlatform();

        try {
            app(DisableUser::class)($admin, $admin, 'Oops');
            $this->fail('Disabling yourself must be refused.');
        } catch (DomainException $e) {
            $this->assertSame('self_disable', $e->errorCode());
        }

        $this->assertFalse($admin->fresh()->isDisabled());
        $this->assertSame(0, $this->auditCount('user.disabled'));
    }

    #[Test]
    public function disabling_needs_a_reason(): void
    {
        $admin = $this->signInAsPlatform();
        $target = User::factory()->create();

        foreach (['', '   ', str_repeat('r', 501)] as $reason) {
            try {
                app(DisableUser::class)($target, $admin, $reason);
                $this->fail('An unusable reason must be refused.');
            } catch (DomainException $e) {
                $this->assertContains($e->errorCode(), ['reason_required', 'reason_too_long']);
            }
        }

        $this->assertFalse($target->fresh()->isDisabled());
    }

    #[Test]
    public function disabling_twice_is_harmless_and_audited_once(): void
    {
        $admin = $this->signInAsPlatform();
        $target = User::factory()->create();

        app(DisableUser::class)($target, $admin, 'First');
        app(DisableUser::class)($target, $admin, 'Second');

        $this->assertSame(1, $this->auditCount('user.disabled'));
    }

    #[Test]
    public function enabling_restores_the_account_and_is_audited(): void
    {
        $admin = $this->signInAsPlatform();
        $target = User::factory()->disabled()->create();

        app(EnableUser::class)($target, $admin, 'Identity confirmed by phone');

        $this->assertFalse($target->fresh()->isDisabled());
        $audit = $this->audit('user.enabled');
        $this->assertSame('platform', $audit->context);
        $this->assertSame($target->id, $audit->subject_id);
        $this->assertSame('disabled', $audit->before['status']);
        $this->assertSame('active', $audit->after['status']);
        $this->assertSame('Identity confirmed by phone', $audit->metadata['reason']);

        // Enabling an account that is already enabled changes nothing and records nothing.
        app(EnableUser::class)($target, $admin);
        $this->assertSame(1, $this->auditCount('user.enabled'));
    }

    #[Test]
    public function the_reason_for_enabling_is_optional_but_not_unbounded(): void
    {
        $admin = $this->signInAsPlatform();
        $target = User::factory()->disabled()->create();

        try {
            app(EnableUser::class)($target, $admin, str_repeat('r', 501));
            $this->fail('An over-long reason must be refused.');
        } catch (DomainException $e) {
            $this->assertSame('reason_too_long', $e->errorCode());
        }
        $this->assertTrue($target->fresh()->isDisabled());

        app(EnableUser::class)($target, $admin, null);
        $this->assertFalse($target->fresh()->isDisabled());
    }

    #[Test]
    public function only_someone_who_manages_administrators_can_disable_or_enable_accounts(): void
    {
        $target = User::factory()->create();

        foreach (['platform_admin', 'platform_support'] as $role) {
            $actor = $this->signInAsPlatform($role);

            foreach ([fn () => app(DisableUser::class)($target, $actor, 'No'), fn () => app(EnableUser::class)($target, $actor, 'No')] as $attempt) {
                try {
                    $attempt();
                    $this->fail("{$role} must not be able to change accounts.");
                } catch (AuthorizationException) {
                    $this->assertFalse($target->fresh()->isDisabled());
                }
            }
        }
        $this->assertSame(0, $this->auditCount('user.disabled'));
    }

    // ── resending verification ──────────────────────────────────────────

    #[Test]
    public function resending_verification_emails_the_account_and_is_audited(): void
    {
        $this->signInAsPlatform('platform_support');
        $target = User::factory()->unverified()->create();
        Notification::fake();

        app(ResendVerification::class)($target);

        Notification::assertSentTo($target, VerifyEmail::class);
        $audit = $this->audit('user.verification_resent');
        $this->assertSame('platform', $audit->context);
        $this->assertSame($target->id, $audit->subject_id);
    }

    #[Test]
    public function verification_is_not_sent_to_a_verified_or_a_disabled_account(): void
    {
        $this->signInAsPlatform('platform_support');
        Notification::fake();

        foreach ([
            'verified' => [User::factory()->create(), 'already_verified'],
            'disabled' => [User::factory()->unverified()->disabled()->create(), 'user_disabled'],
        ] as $name => [$target, $code]) {
            try {
                app(ResendVerification::class)($target);
                $this->fail("{$name}: must be refused.");
            } catch (DomainException $e) {
                $this->assertSame($code, $e->errorCode(), $name);
            }
        }

        Notification::assertNothingSent();
        $this->assertSame(0, $this->auditCount('user.verification_resent'));
    }

    #[Test]
    public function one_account_cannot_be_flooded_with_verification_emails(): void
    {
        $this->signInAsPlatform('platform_support');
        $target = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();
        Notification::fake();

        foreach (range(1, 3) as $_) {
            app(ResendVerification::class)($target);
        }

        try {
            app(ResendVerification::class)($target);
            $this->fail('The fourth email within the window must be refused.');
        } catch (DomainException $e) {
            $this->assertSame('throttled', $e->errorCode());
        }
        Notification::assertSentToTimes($target, VerifyEmail::class, 3);

        // The limit is per account: someone else's mailbox is unaffected.
        app(ResendVerification::class)($other);
        Notification::assertSentTo($other, VerifyEmail::class);
    }
}
