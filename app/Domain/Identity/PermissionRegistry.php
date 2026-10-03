<?php

namespace App\Domain\Identity;

/**
 * The permission catalogue. Code-defined (behaviour depends on it); roles that
 * hold these keys are data. `permissions:sync` mirrors it into the database.
 *
 * Organization permissions are checked against the current membership's roles;
 * platform.* permissions against the user's platform roles. A platform
 * permission never grants access to tenant data.
 */
final class PermissionRegistry
{
    public const SCOPE_ORGANIZATION = 'organization';

    public const SCOPE_PLATFORM = 'platform';

    /** @var array<string, array{scope: string, group: string, label: string, description: string, sensitive: bool}>|null */
    private static ?array $all = null;

    /** @return array<string, array{scope: string, group: string, label: string, description: string, sensitive: bool}> */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }

        $o = self::SCOPE_ORGANIZATION;
        $p = self::SCOPE_PLATFORM;

        $definitions = [
            // Clients
            ['clients.view', $o, 'Clients', 'View assigned clients', 'Demographics and contact details of clients the user is the primary clinician for.', true],
            ['clients.view_all', $o, 'Clients', 'View all clients', 'Demographics and contact details of every client in the organization.', true],
            ['clients.create', $o, 'Clients', 'Create clients', '', false],
            ['clients.edit', $o, 'Clients', 'Edit clients', 'Edit demographics and contacts of clients the user can view.', false],
            ['clients.archive', $o, 'Clients', 'Archive and restore clients', '', false],

            // Scheduling
            ['appointments.view', $o, 'Scheduling', 'View own appointments', 'Appointments where the user is the clinician.', false],
            ['appointments.view_all', $o, 'Scheduling', 'View all appointments', 'Every appointment on the organization calendar.', false],
            ['appointments.create', $o, 'Scheduling', 'Book appointments', '', false],
            ['appointments.edit', $o, 'Scheduling', 'Edit and reschedule appointments', 'Change times, details and status (check in, complete).', false],
            ['appointments.cancel', $o, 'Scheduling', 'Cancel appointments', 'Cancel or mark no-show.', false],
            ['appointments.overbook', $o, 'Scheduling', 'Double-book', 'Knowingly book over an existing appointment.', false],
            ['availability.manage_own', $o, 'Scheduling', 'Manage own availability', 'Own availability and blocked time.', false],
            ['availability.manage_all', $o, 'Scheduling', 'Manage everyone\'s availability', 'Availability and blocked time for all staff, and organization-wide closures.', false],

            // Organization configuration
            ['organization.settings.manage', $o, 'Organization', 'Manage organization settings', 'Profile, regional formats, scheduling and client settings.', false],
            ['locations.manage', $o, 'Organization', 'Manage locations', '', false],
            ['services.manage', $o, 'Organization', 'Manage services', 'Service catalogue, prices and who provides them.', false],
            ['team.view', $o, 'Team', 'View team', '', false],
            ['team.manage', $o, 'Team', 'Manage team', 'Invite, edit and deactivate staff; assign roles.', false],
            ['roles.manage', $o, 'Team', 'Manage roles & permissions', 'Create roles and change what they allow.', true],
            ['audit.view', $o, 'Organization', 'View audit log', 'Who did what, when, in this organization.', true],
            ['demo.manage', $o, 'Organization', 'Manage demo data', 'Load, reset and delete demo data.', false],
            ['reports.view', $o, 'Reports', 'View reports', '', false],

            // Platform (Super Admin console)
            ['platform.dashboard.view', $p, 'Platform', 'View platform dashboard', '', false],
            ['platform.organizations.view', $p, 'Platform', 'View organizations', 'Organization profile, status, plan and usage counts — no client data.', false],
            ['platform.organizations.manage', $p, 'Platform', 'Create and edit organizations', '', false],
            ['platform.organizations.lifecycle', $p, 'Platform', 'Suspend, archive and restore organizations', '', true],
            ['platform.plans.manage', $p, 'Platform', 'Manage plans', 'Plans, prices and plan features.', false],
            ['platform.subscriptions.manage', $p, 'Platform', 'Manage subscriptions', 'Change an organization\'s plan or subscription status.', false],
            ['platform.features.manage', $p, 'Platform', 'Manage entitlements', 'Per-organization feature and limit overrides.', false],
            ['platform.settings.manage', $p, 'Platform', 'Manage platform settings', '', true],
            ['platform.users.view', $p, 'Platform', 'View users', 'Account list (name, email, status, organizations).', false],
            ['platform.users.manage', $p, 'Platform', 'Manage accounts', 'Disable and re-enable user accounts.', true],
            ['platform.admins.manage', $p, 'Platform', 'Manage platform administrators', 'Grant and revoke platform roles.', true],
            ['platform.audit.view', $p, 'Platform', 'View platform audit log', 'Platform-level actions only.', true],
            ['platform.support.act', $p, 'Platform', 'Support actions', 'Resend verification and invitations.', false],
        ];

        $all = [];
        foreach ($definitions as [$key, $scope, $group, $label, $description, $sensitive]) {
            $all[$key] = compact('scope', 'group', 'label', 'description') + ['sensitive' => $sensitive];
        }

        return self::$all = $all;
    }

    public static function exists(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    public static function isPlatform(string $key): bool
    {
        return (self::all()[$key]['scope'] ?? null) === self::SCOPE_PLATFORM;
    }

    /** @return list<string> */
    public static function keys(string $scope): array
    {
        return array_keys(array_filter(self::all(), fn (array $p) => $p['scope'] === $scope));
    }

    /** @return array<string, array<string, array{scope: string, group: string, label: string, description: string, sensitive: bool}>> group => key => definition */
    public static function grouped(string $scope): array
    {
        $grouped = [];
        foreach (self::all() as $key => $definition) {
            if ($definition['scope'] === $scope) {
                $grouped[$definition['group']][$key] = $definition;
            }
        }

        return $grouped;
    }
}
