<?php

namespace App\Domain\Platform;

/**
 * Everything a person can do in the Super Admin console, and the permission it
 * takes. One list, so the rule "which permission does this action need" has a
 * single home that callers (controllers, commands, jobs) and tests all read.
 *
 * Several actions can share one permission (two cases may map to the same key);
 * the case says WHAT is being done, permission() says what it takes.
 */
enum PlatformAbility
{
    case ViewDashboard;
    case ViewOrganizations;
    case CreateOrganization;
    case UpdateOrganization;
    case ChangeOrganizationStatus;
    case StartSubscription;
    case ChangeSubscriptionPlan;
    case ChangeSubscriptionStatus;
    case SetEntitlementOverride;
    case RemoveEntitlementOverride;
    case CreatePlan;
    case UpdatePlan;
    case UpdatePlatformSettings;
    case ViewUsers;
    case DisableUser;
    case EnableUser;
    case ResendVerification;
    case ViewAdministrators;
    case GrantPlatformRole;
    case RevokePlatformRole;
    case ViewAuditLog;

    /** The platform.* permission key (see PermissionRegistry) this ability requires. */
    public function permission(): string
    {
        return match ($this) {
            self::ViewDashboard => 'platform.dashboard.view',
            self::ViewOrganizations => 'platform.organizations.view',
            self::CreateOrganization, self::UpdateOrganization => 'platform.organizations.manage',
            self::ChangeOrganizationStatus => 'platform.organizations.lifecycle',
            self::StartSubscription, self::ChangeSubscriptionPlan, self::ChangeSubscriptionStatus => 'platform.subscriptions.manage',
            self::SetEntitlementOverride, self::RemoveEntitlementOverride => 'platform.features.manage',
            self::CreatePlan, self::UpdatePlan => 'platform.plans.manage',
            self::UpdatePlatformSettings => 'platform.settings.manage',
            self::ViewUsers => 'platform.users.view',
            // Disabling an account is access-removing and reaches platform staff too:
            // it takes the same permission as managing platform administrators.
            self::DisableUser, self::EnableUser => 'platform.users.manage',
            self::ViewAdministrators, self::GrantPlatformRole, self::RevokePlatformRole => 'platform.admins.manage',
            self::ResendVerification => 'platform.support.act',
            self::ViewAuditLog => 'platform.audit.view',
        };
    }

    /**
     * Actions that remove or change access, or change what organizations get.
     * Adapters ask for a recent password confirmation before running them.
     */
    public function requiresPasswordConfirmation(): bool
    {
        return in_array($this, [
            self::ChangeOrganizationStatus,
            self::ChangeSubscriptionPlan,
            self::ChangeSubscriptionStatus,
            self::UpdatePlan,
            self::UpdatePlatformSettings,
            self::DisableUser,
            self::EnableUser,
            self::GrantPlatformRole,
            self::RevokePlatformRole,
        ], true);
    }
}
