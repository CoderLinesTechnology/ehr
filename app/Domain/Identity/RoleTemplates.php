<?php

namespace App\Domain\Identity;

/**
 * Default roles. Organization templates are instantiated per organization at
 * creation (is_system = true) and are then the organization's data to edit;
 * locked roles keep every permission of their scope.
 */
final class RoleTemplates
{
    public const ORG_ADMIN = 'org_admin';

    public const SUPER_ADMIN = 'super_admin';

    /** @return array<string, array{name: string, description: string, locked: bool, permissions: list<string>|'*'}> */
    public static function organization(): array
    {
        $clinician = [
            'clients.view', 'clients.create', 'clients.edit',
            'appointments.view', 'appointments.create', 'appointments.edit', 'appointments.cancel',
            'availability.manage_own', 'team.view',
        ];

        return [
            self::ORG_ADMIN => [
                'name' => 'Organization Administrator',
                'description' => 'Full control of the organization, its staff, roles and settings.',
                'locked' => true,
                'permissions' => '*',
            ],
            'practice_manager' => [
                'name' => 'Practice Manager',
                'description' => 'Runs day-to-day operations: scheduling, staff, services and settings.',
                'locked' => false,
                'permissions' => [
                    'clients.view', 'clients.view_all', 'clients.create', 'clients.edit', 'clients.archive',
                    'appointments.view', 'appointments.view_all', 'appointments.create', 'appointments.edit',
                    'appointments.cancel', 'appointments.overbook', 'availability.manage_own', 'availability.manage_all',
                    'organization.settings.manage', 'locations.manage', 'services.manage', 'team.view', 'team.manage',
                    'audit.view', 'demo.manage', 'reports.view',
                ],
            ],
            'clinician' => [
                'name' => 'Clinician',
                'description' => 'Sees and schedules their own clients.',
                'locked' => false,
                'permissions' => $clinician,
            ],
            'supervisor' => [
                'name' => 'Clinical Supervisor',
                'description' => 'Clinician who can see every client and appointment.',
                'locked' => false,
                'permissions' => [...$clinician, 'clients.view_all', 'appointments.view_all', 'reports.view'],
            ],
            'receptionist' => [
                'name' => 'Receptionist',
                'description' => 'Front desk: registers clients and manages the calendar. No clinical access.',
                'locked' => false,
                'permissions' => [
                    'clients.view', 'clients.view_all', 'clients.create', 'clients.edit',
                    'appointments.view', 'appointments.view_all', 'appointments.create', 'appointments.edit', 'appointments.cancel',
                    'availability.manage_own', 'availability.manage_all', 'team.view',
                ],
            ],
            'billing' => [
                'name' => 'Billing Staff',
                'description' => 'Billing and payments. No clinical access.',
                'locked' => false,
                'permissions' => ['clients.view', 'clients.view_all', 'appointments.view', 'appointments.view_all', 'reports.view', 'team.view'],
            ],
            'staff' => [
                'name' => 'Staff',
                'description' => 'Basic access for other staff.',
                'locked' => false,
                'permissions' => ['appointments.view', 'team.view'],
            ],
        ];
    }

    /** @return array<string, array{name: string, description: string, locked: bool, permissions: list<string>|'*'}> */
    public static function platform(): array
    {
        return [
            self::SUPER_ADMIN => [
                'name' => 'Super Admin',
                'description' => 'Full control of the platform. Requires two-factor authentication.',
                'locked' => true,
                'permissions' => '*',
            ],
            'platform_admin' => [
                'name' => 'Platform Administrator',
                'description' => 'Manages organizations, plans and entitlements.',
                'locked' => false,
                'permissions' => [
                    'platform.dashboard.view', 'platform.organizations.view', 'platform.organizations.manage',
                    'platform.organizations.lifecycle', 'platform.plans.manage', 'platform.subscriptions.manage',
                    'platform.features.manage', 'platform.users.view', 'platform.audit.view', 'platform.support.act',
                ],
            ],
            'platform_support' => [
                'name' => 'Platform Support',
                'description' => 'Read-only operational view plus non-destructive support actions.',
                'locked' => false,
                'permissions' => ['platform.dashboard.view', 'platform.organizations.view', 'platform.users.view', 'platform.support.act'],
            ],
        ];
    }

    /** @return list<string> */
    public static function resolve(array $template, string $scope): array
    {
        return $template['permissions'] === '*' ? PermissionRegistry::keys($scope) : $template['permissions'];
    }
}
