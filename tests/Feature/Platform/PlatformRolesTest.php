<?php

namespace Tests\Feature\Platform;

use App\Domain\Identity\PermissionRegistry;
use App\Domain\Identity\PermissionResolver;
use App\Domain\Identity\PlatformRoles\GrantPlatformRole;
use App\Domain\Identity\PlatformRoles\RevokePlatformRole;
use App\Domain\Identity\RoleTemplates;
use App\Domain\Identity\SyncPermissionCatalogue;
use App\Domain\Platform\Users\DisableUser;
use App\Domain\Shared\DomainException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;

class PlatformRolesTest extends PlatformTestCase
{
    private function grant(User $target, string $roleKey, ?User $actor = null, string $reason = 'Joins the support rota'): Role
    {
        return app(GrantPlatformRole::class)($target, $roleKey, $actor ?? auth()->user(), $reason);
    }

    private function revoke(User $target, string $roleKey, ?User $actor = null, string $reason = 'Left the team'): void
    {
        app(RevokePlatformRole::class)($target, $roleKey, $actor ?? auth()->user(), $reason);
    }

    /** @return list<string> */
    private function roleKeysOf(User $user): array
    {
        return DB::table('platform_user_roles AS pur')->join('roles AS r', 'r.id', '=', 'pur.role_id')
            ->where('pur.user_id', $user->id)->orderBy('r.key')->pluck('r.key')->all();
    }

    /** A platform role of the test's own, with exactly these permissions. */
    private function customRole(string $key, array $permissions): Role
    {
        $role = new Role;
        $role->forceFill([
            'scope' => Role::SCOPE_PLATFORM, 'organization_id' => null, 'key' => $key, 'name' => ucfirst(str_replace('_', ' ', $key)),
            'description' => null, 'is_system' => false, 'is_locked' => false,
        ])->save();
        SyncPermissionCatalogue::grant($role, $permissions);

        return $role;
    }

    // ── granting ────────────────────────────────────────────────────────

    #[Test]
    public function granting_a_role_gives_the_permissions_at_once_and_is_audited(): void
    {
        $admin = $this->signInAsPlatform();
        $target = User::factory()->create();
        $gate = Gate::forUser($target);
        $this->assertFalse($gate->allows('platform.users.view'), 'No role yet (and this memoises the answer).');

        $role = $this->grant($target, 'platform_support', reason: 'Joins the support rota');

        $this->assertSame('platform_support', $role->key);
        $this->assertSame(['platform_support'], $this->roleKeysOf($target));
        $this->assertTrue($gate->allows('platform.users.view'), 'The memoised permissions were flushed.');

        $pivot = DB::table('platform_user_roles')->where('user_id', $target->id)->first();
        $this->assertSame($admin->id, $pivot->granted_by_user_id);
        $this->assertNotNull($pivot->granted_at);
        $this->assertSame('platform', $pivot->scope);

        $audit = $this->audit('platform.role_granted');
        $this->assertSame('platform', $audit->context);
        $this->assertSame('user', $audit->subject_type);
        $this->assertSame($target->id, $audit->subject_id);
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertNull($audit->organization_id);
        $this->assertSame([], $audit->before['roles']);
        $this->assertSame(['platform_support'], $audit->after['roles']);
        $this->assertSame('platform_support', $audit->metadata['role']);
        $this->assertSame('Joins the support rota', $audit->metadata['reason']);
    }

    #[Test]
    public function granting_a_role_ends_the_persons_existing_sessions_and_rotates_their_remember_token(): void
    {
        $this->signInAsPlatform();
        $target = User::factory()->create(['remember_token' => 'old-remember-token']);
        $other = User::factory()->create();
        foreach (['session-a', 'session-b'] as $id) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $target->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => 'x', 'last_activity' => time()]);
        }
        DB::table('sessions')->insert(['id' => 'session-other', 'user_id' => $other->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => 'x', 'last_activity' => time()]);

        $this->grant($target, 'platform_support');

        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count(), 'Nothing opened before the grant carries console access.');
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count(), 'Other people stay signed in.');
        $this->assertNotSame('old-remember-token', $target->fresh()->remember_token);
        $this->assertSame(2, $this->audit('platform.role_granted')->metadata['sessions_ended']);
    }

    #[Test]
    public function a_person_can_hold_several_platform_roles(): void
    {
        $this->signInAsPlatform();
        $target = User::factory()->create();

        $this->grant($target, 'platform_support');
        $this->grant($target, 'platform_admin');

        $this->assertSame(['platform_admin', 'platform_support'], $this->roleKeysOf($target));
        $audit = $this->audit('platform.role_granted');
        $this->assertEqualsCanonicalizing(['platform_support', 'platform_admin'], $audit->after['roles']);
        $this->assertSame(['platform_support'], $audit->before['roles']);
    }

    #[Test]
    public function granting_is_refused_for_an_unverified_email_a_disabled_account_a_repeat_or_an_unknown_role(): void
    {
        $this->signInAsPlatform();
        $unverified = User::factory()->unverified()->create();
        $disabled = User::factory()->disabled()->create();
        $holder = User::factory()->create();
        $this->grant($holder, 'platform_support');

        foreach ([
            'unverified email' => [fn () => $this->grant($unverified, 'platform_support'), 'unverified_email'],
            'disabled account' => [fn () => $this->grant($disabled, 'platform_support'), 'user_disabled'],
            'already granted' => [fn () => $this->grant($holder, 'platform_support'), 'already_granted'],
            'unknown role' => [fn () => $this->grant(User::factory()->create(), 'god_mode'), 'unknown_role'],
            'an organization role key' => [fn () => $this->grant(User::factory()->create(), RoleTemplates::ORG_ADMIN), 'unknown_role'],
            'blank reason' => [fn () => $this->grant(User::factory()->create(), 'platform_support', reason: ' '), 'reason_required'],
            'long reason' => [fn () => $this->grant(User::factory()->create(), 'platform_support', reason: str_repeat('r', 501)), 'reason_too_long'],
        ] as $name => [$attempt, $code]) {
            try {
                $attempt();
                $this->fail("{$name}: must be refused.");
            } catch (DomainException $e) {
                $this->assertSame($code, $e->errorCode(), $name);
            }
        }

        $this->assertSame([], $this->roleKeysOf($unverified));
        $this->assertSame([], $this->roleKeysOf($disabled));
        $this->assertSame(1, $this->auditCount('platform.role_granted'), 'Only the set-up grant was audited.');
    }

    #[Test]
    public function nobody_can_change_their_own_roles(): void
    {
        $admin = $this->signInAsPlatform();

        foreach ([
            'grant to self' => fn () => $this->grant($admin, 'platform_support'),
            'revoke from self' => fn () => $this->revoke($admin, RoleTemplates::SUPER_ADMIN),
        ] as $name => $attempt) {
            try {
                $attempt();
                $this->fail("{$name}: must be refused.");
            } catch (DomainException $e) {
                $this->assertSame('self_change', $e->errorCode(), $name);
            }
        }

        $this->assertSame([RoleTemplates::SUPER_ADMIN], $this->roleKeysOf($admin));
    }

    #[Test]
    public function an_administrator_can_only_hand_out_a_role_whose_permissions_they_hold_themselves(): void
    {
        $this->customRole('role_manager', ['platform.admins.manage', 'platform.users.view']);
        $this->customRole('view_only', ['platform.users.view']);
        $actor = $this->signInAsPlatform('role_manager');
        $target = User::factory()->create();

        foreach (['platform_support', RoleTemplates::SUPER_ADMIN, 'platform_admin'] as $tooMuch) {
            try {
                $this->grant($target, $tooMuch);
                $this->fail("A role manager must not be able to grant {$tooMuch}.");
            } catch (DomainException $e) {
                $this->assertSame('privilege_escalation', $e->errorCode(), $tooMuch);
            }
        }
        $this->assertSame([], $this->roleKeysOf($target));

        $this->grant($target, 'view_only');
        $this->assertSame(['view_only'], $this->roleKeysOf($target), 'A role within their own permissions is fine.');
    }

    #[Test]
    public function only_an_enabled_administrator_with_two_factor_and_the_admins_permission_may_grant_or_revoke(): void
    {
        $target = User::factory()->create();
        $holder = User::factory()->create();

        foreach ([
            'platform admin (no admins permission)' => fn () => $this->signInAsPlatform('platform_admin'),
            'platform support' => fn () => $this->signInAsPlatform('platform_support'),
            'super admin without confirmed two-factor' => fn () => $this->signInAsPlatform(RoleTemplates::SUPER_ADMIN, withTwoFactor: false),
        ] as $name => $signIn) {
            $actor = $signIn();

            foreach ([
                'grant' => fn () => $this->grant($target, 'platform_support', $actor),
                'revoke' => fn () => $this->revoke($holder, 'platform_support', $actor),
            ] as $what => $attempt) {
                try {
                    $attempt();
                    $this->fail("{$name} must not be able to {$what}.");
                } catch (AuthorizationException) {
                    $this->assertTrue(true);
                }
            }
        }

        $disabledAdmin = $this->platformUser();
        $disabledAdmin->forceFill(['status' => 'disabled'])->save();
        $this->expectException(AuthorizationException::class);
        $this->grant($target, 'platform_support', $disabledAdmin->fresh());
    }

    #[Test]
    public function an_authorization_failure_changes_nothing(): void
    {
        $actor = $this->signInAsPlatform('platform_support');
        $target = User::factory()->create();

        try {
            $this->grant($target, 'platform_support', $actor);
        } catch (AuthorizationException) {
        }

        $this->assertSame([], $this->roleKeysOf($target));
        $this->assertSame(0, $this->auditCount('platform.role_granted'));
    }

    // ── revoking ────────────────────────────────────────────────────────

    #[Test]
    public function revoking_removes_one_role_and_the_permissions_go_with_it(): void
    {
        $admin = $this->signInAsPlatform();
        $target = User::factory()->create();
        $this->grant($target, 'platform_support');
        $this->grant($target, 'platform_admin');
        $gate = Gate::forUser($target);
        $this->assertTrue($gate->allows('platform.organizations.manage'), 'Warms the memoised permissions.');

        $this->revoke($target, 'platform_admin', reason: 'Moved to support only');

        $this->assertSame(['platform_support'], $this->roleKeysOf($target));
        $this->assertFalse($gate->allows('platform.organizations.manage'), 'Removed permissions are gone in the same request.');
        $this->assertTrue($gate->allows('platform.users.view'), 'The other role still applies.');

        $audit = $this->audit('platform.role_revoked');
        $this->assertSame('platform', $audit->context);
        $this->assertSame($target->id, $audit->subject_id);
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertEqualsCanonicalizing(['platform_admin', 'platform_support'], $audit->before['roles']);
        $this->assertSame(['platform_support'], $audit->after['roles']);
        $this->assertSame('platform_admin', $audit->metadata['role']);
        $this->assertSame('Moved to support only', $audit->metadata['reason']);
    }

    #[Test]
    public function revoking_is_refused_for_a_role_not_held_an_unknown_role_or_a_blank_reason(): void
    {
        $this->signInAsPlatform();
        $target = User::factory()->create();
        $this->grant($target, 'platform_support');

        foreach ([
            'not held' => [fn () => $this->revoke($target, 'platform_admin'), 'not_granted'],
            'unknown role' => [fn () => $this->revoke($target, 'god_mode'), 'unknown_role'],
            'blank reason' => [fn () => $this->revoke($target, 'platform_support', reason: ''), 'reason_required'],
        ] as $name => [$attempt, $code]) {
            try {
                $attempt();
                $this->fail("{$name}: must be refused.");
            } catch (DomainException $e) {
                $this->assertSame($code, $e->errorCode(), $name);
            }
        }

        $this->assertSame(['platform_support'], $this->roleKeysOf($target));
        $this->assertSame(0, $this->auditCount('platform.role_revoked'));
    }

    #[Test]
    public function the_last_active_super_admin_can_never_be_removed(): void
    {
        // The actor holds every platform permission through a role of their own, so only the guard can stop them.
        $this->customRole('owner_like', PermissionRegistry::keys('platform'));
        $actor = $this->signInAsPlatform('owner_like');
        $onlySuperAdmin = $this->platformUser();

        try {
            $this->revoke($onlySuperAdmin, RoleTemplates::SUPER_ADMIN, $actor);
            $this->fail('The only Super Admin must stay.');
        } catch (DomainException $e) {
            $this->assertSame('last_super_admin', $e->errorCode());
        }
        $this->assertSame([RoleTemplates::SUPER_ADMIN], $this->roleKeysOf($onlySuperAdmin));
        $this->assertSame(0, $this->auditCount('platform.role_revoked'));

        // A second Super Admin makes the removal legal...
        $second = $this->platformUser();
        $this->revoke($onlySuperAdmin, RoleTemplates::SUPER_ADMIN, $actor);
        $this->assertSame([], $this->roleKeysOf($onlySuperAdmin));

        // ...and now the second one is the last.
        try {
            $this->revoke($second, RoleTemplates::SUPER_ADMIN, $actor);
            $this->fail('The new last Super Admin must stay.');
        } catch (DomainException $e) {
            $this->assertSame('last_super_admin', $e->errorCode());
        }
    }

    #[Test]
    public function a_disabled_super_admin_does_not_count_as_one_that_remains(): void
    {
        $this->customRole('owner_like', PermissionRegistry::keys('platform'));
        $actor = $this->signInAsPlatform('owner_like');
        $active = $this->platformUser();
        $disabled = $this->platformUser();
        $disabled->forceFill(['status' => 'disabled'])->save();

        try {
            $this->revoke($active, RoleTemplates::SUPER_ADMIN, $actor);
            $this->fail('The only ACTIVE Super Admin must stay.');
        } catch (DomainException $e) {
            $this->assertSame('last_super_admin', $e->errorCode());
        }

        // Removing the disabled one is fine: the active one remains.
        $this->revoke($disabled, RoleTemplates::SUPER_ADMIN, $actor);
        $this->assertSame([], $this->roleKeysOf($disabled));
    }

    #[Test]
    public function two_super_admins_cannot_leave_the_platform_without_one(): void
    {
        $first = $this->signInAsPlatform();
        $second = $this->platformUser();

        // The first removes the second...
        $this->revoke($second, RoleTemplates::SUPER_ADMIN, $first);

        // ...so when the second (whose request was already in flight) tries to remove the first, they are no longer an administrator.
        try {
            $this->revoke($first, RoleTemplates::SUPER_ADMIN, $second->fresh());
            $this->fail('A removed Super Admin cannot remove anyone.');
        } catch (AuthorizationException) {
            $this->assertSame([RoleTemplates::SUPER_ADMIN], $this->roleKeysOf($first));
        }
    }

    #[Test]
    public function an_administrator_can_only_remove_a_role_whose_permissions_they_hold_themselves(): void
    {
        $this->customRole('role_manager', ['platform.admins.manage', 'platform.users.view']);
        $actor = $this->signInAsPlatform('role_manager');
        $target = User::factory()->create();
        $this->customRole('second_super', PermissionRegistry::keys('platform'));
        // Setting up through the domain would (rightly) refuse: attach the pivot directly.
        $target->platformRoles()->attach(Role::query()->platform()->where('key', 'second_super')->firstOrFail()->id, ['scope' => 'platform', 'granted_at' => now()]);

        try {
            $this->revoke($target, 'second_super', $actor);
            $this->fail('A role manager must not be able to remove a more powerful role.');
        } catch (DomainException $e) {
            $this->assertSame('privilege_escalation', $e->errorCode());
        }
        $this->assertSame(['second_super'], $this->roleKeysOf($target));
    }

    #[Test]
    public function changing_roles_locks_the_super_admin_role_row(): void
    {
        $admin = $this->signInAsPlatform();
        $target = User::factory()->create();
        $this->grant($target, 'platform_support');

        $this->revoke($target, 'platform_support');

        // The role row is the mutex behind "at least one Super Admin remains": a second session cannot take it now.
        $superAdmin = Role::query()->platform()->where('key', RoleTemplates::SUPER_ADMIN)->firstOrFail();
        $this->assertRowIsLocked('roles', 'id', $superAdmin->id);
    }

    #[Test]
    public function the_permission_resolver_is_flushed_after_every_change(): void
    {
        $this->signInAsPlatform();
        $target = User::factory()->create();
        $resolver = app(PermissionResolver::class);

        $this->assertFalse($resolver->isPlatformUser($target));
        $this->grant($target, 'platform_support');
        $this->assertTrue($resolver->isPlatformUser($target));
        $this->revoke($target, 'platform_support');
        $this->assertFalse($resolver->isPlatformUser($target));
    }

    #[Test]
    public function disabling_the_last_active_super_admin_is_refused_too(): void
    {
        $this->customRole('owner_like', PermissionRegistry::keys('platform'));
        $actor = $this->signInAsPlatform('owner_like');
        $onlySuperAdmin = $this->platformUser();

        try {
            app(DisableUser::class)($onlySuperAdmin, $actor, 'Compromised');
            $this->fail('The only Super Admin cannot be disabled.');
        } catch (DomainException $e) {
            $this->assertSame('last_super_admin', $e->errorCode());
        }
        $this->assertFalse($onlySuperAdmin->fresh()->isDisabled());

        $this->platformUser(); // a second one
        app(DisableUser::class)($onlySuperAdmin, $actor, 'Compromised');
        $this->assertTrue($onlySuperAdmin->fresh()->isDisabled());
    }
}
