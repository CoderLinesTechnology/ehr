<?php

namespace Tests\Feature\Settings\Concerns;

use App\Domain\Identity\PermissionResolver;
use App\Domain\Identity\SyncPermissionCatalogue;
use App\Domain\Shared\DomainException;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use App\Notifications\StaffInvitationNotification;
use Closure;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Helpers for domain-level tests of the settings, team and invitation actions. The actions take their
 * tenant and acting person from the context (as they do inside a request), so every call is made
 * through actAs(): authenticated as the member's user, inside the member's organization.
 */
trait WorksInsideOrganizations
{
    /** Run $callback as $actor: signed in as their user, inside their organization, with their membership acting. */
    protected function actAs(OrganizationMembership $actor, Closure $callback): mixed
    {
        $organization = Organization::query()->findOrFail($actor->organization_id);
        $user = User::query()->findOrFail($actor->user_id);

        $this->actingAs($user);

        return $this->inTenant($organization, $callback, $actor);
    }

    /** A custom (non-system, unlocked) role holding exactly these permission keys. */
    protected function makeRole(Organization $organization, string $name, array $permissions): Role
    {
        return $this->inTenant($organization, function () use ($organization, $name, $permissions) {
            $role = new Role;
            $role->fill(['name' => $name, 'description' => null]);
            $role->forceFill([
                'organization_id' => $organization->id,
                'scope' => Role::SCOPE_ORGANIZATION,
                'key' => 'custom_'.Str::lower(Str::random(8)),
                'is_system' => false,
                'is_locked' => false,
            ])->save();
            SyncPermissionCatalogue::grant($role, $permissions);

            return $role;
        });
    }

    /** The organization's role for a template key ('org_admin', 'practice_manager', 'clinician', …). */
    protected function roleOf(Organization $organization, string $key): Role
    {
        return Role::query()->forOrganization($organization->id)->where('key', $key)->firstOrFail();
    }

    /** Add a role to a member (not through the domain: test setup). */
    protected function giveRole(OrganizationMembership $member, Role $role): void
    {
        $this->inTenant(Organization::query()->findOrFail($member->organization_id), fn () => $member->roles()->attach($role->id));
        app(PermissionResolver::class)->flush();
    }

    /** Grant a permission to an organization role (not through the domain: test setup). */
    protected function grantToRole(Organization $organization, string $roleKey, string ...$permissions): void
    {
        SyncPermissionCatalogue::grant($this->roleOf($organization, $roleKey), array_values($permissions));
        app(PermissionResolver::class)->flush();
    }

    /** Re-read a membership from the database, whatever organization it is in. */
    protected function reload(OrganizationMembership $member): ?OrganizationMembership
    {
        return OrganizationMembership::acrossTenants()->find($member->id);
    }

    /** @return list<string> permission keys a role holds, sorted */
    protected function permissionsOfRole(Role $role): array
    {
        $keys = DB::table('role_permissions')->where('role_id', $role->id)->pluck('permission_key')->all();
        sort($keys);

        return $keys;
    }

    /** @return list<string> role keys a membership holds, sorted */
    protected function roleKeysOf(OrganizationMembership $member): array
    {
        $keys = DB::table('membership_roles')
            ->join('roles', 'roles.id', '=', 'membership_roles.role_id')
            ->where('membership_roles.membership_id', $member->id)
            ->pluck('roles.key')->all();
        sort($keys);

        return $keys;
    }

    protected function auditCount(string $action, ?Organization $organization = null): int
    {
        return AuditLog::query()
            ->where('action', $action)
            ->when($organization, fn ($q) => $q->where('organization_id', $organization->id))
            ->count();
    }

    protected function lastAudit(string $action, ?Organization $organization = null): ?AuditLog
    {
        return AuditLog::query()
            ->where('action', $action)
            ->when($organization, fn ($q) => $q->where('organization_id', $organization->id))
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->first();
    }

    /** The callback must be refused with a DomainException carrying this machine code. */
    protected function assertRefused(Closure $callback, string $code, ?string $field = null): DomainException
    {
        try {
            $callback();
        } catch (DomainException $e) {
            $this->assertSame($code, $e->errorCode(), "Refused for the wrong reason: {$e->userMessage()}");
            if ($field !== null) {
                $this->assertSame($field, $e->field());
            }
            $this->assertNotSame('', $e->userMessage());

            return $e;
        }

        $this->fail("Expected the action to be refused with [{$code}], but it succeeded.");
    }

    /**
     * Invitation emails sent since Notification::fake(), oldest first.
     *
     * @return list<array{email: string, url: string, token: string, notification: StaffInvitationNotification}>
     */
    protected function sentInvitations(): array
    {
        $sent = [];

        Notification::sent(new AnonymousNotifiable, StaffInvitationNotification::class, function ($notification, $channels, $notifiable) use (&$sent) {
            $sent[] = [
                'email' => (string) ($notifiable->routes['mail'] ?? ''),
                'url' => $notification->acceptUrl,
                'token' => basename($notification->acceptUrl),
                'notification' => $notification,
            ];

            return true;
        });

        return $sent;
    }

    /** The most recent invitation email (fails the test when none was sent). */
    protected function lastInvitation(): array
    {
        $sent = $this->sentInvitations();
        $this->assertNotEmpty($sent, 'No invitation email was sent.');

        return end($sent);
    }
}
