<?php

namespace App\Domain\Platform;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\InvitationTokens;
use App\Domain\Identity\MembershipStatus;
use App\Domain\Identity\PermissionRegistry;
use App\Domain\Identity\RoleTemplates;
use App\Domain\Identity\SyncPermissionCatalogue;
use App\Domain\Platform\Events\OrganizationCreated;
use App\Domain\Saas\StartSubscription;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\DomainException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationStatusHistory;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a tenant with everything it needs to work: default roles (from
 * templates), regional settings, the owner's administrator membership (or an
 * invitation when a platform admin creates it for someone), a subscription
 * on the chosen plan, status history and an audit entry — all or nothing.
 */
final class CreateOrganization
{
    /** Never usable as an organization slug (they are subdomains). */
    private const RESERVED_SLUGS = [
        'www', 'app', 'api', 'admin', 'platform', 'mail', 'email', 'smtp', 'ftp', 'static', 'assets', 'cdn',
        'support', 'help', 'status', 'docs', 'blog', 'portal', 'login', 'register', 'auth', 'account', 'billing',
        'dashboard', 'demo', 'test', 'staging', 'dev', 'o', 'p', 'public', 'book', 'booking',
    ];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
        private readonly StartSubscription $startSubscription,
    ) {}

    /**
     * @param  array{name: string, slug?: ?string, country_code: string, timezone: string, currency: string,
     *               locale?: ?string, email?: ?string, phone?: ?string, legal_name?: ?string}  $profile
     */
    public function __invoke(
        array $profile,
        Plan $plan,
        OrganizationStatus $status,
        ?User $owner = null,
        ?string $ownerEmail = null,
        ?User $actor = null,
        AuditContext $auditContext = AuditContext::Platform,
    ): CreatedOrganization {
        if (($owner === null) === ($ownerEmail === null)) {
            throw new \InvalidArgumentException('Provide exactly one of $owner or $ownerEmail.');
        }

        if (! in_array($status, [OrganizationStatus::Pending, OrganizationStatus::Trial, OrganizationStatus::Active], true)) {
            throw new DomainException('A new organization must start as pending, trial or active.', 'invalid_status');
        }

        return DB::transaction(function () use ($profile, $plan, $status, $owner, $ownerEmail, $actor, $auditContext) {
            // Role templates are cloned from the code catalogue: if the table is
            // behind (fresh install, or a deploy that skipped catalogue:sync),
            // sync first so every granted key exists.
            if (DB::table('permissions')->count() !== count(PermissionRegistry::all())) {
                app(SyncPermissionCatalogue::class)();
            }

            $organization = new Organization;
            $organization->fill([
                'name' => trim($profile['name']),
                'legal_name' => $profile['legal_name'] ?? null,
                'email' => $profile['email'] ?? null,
                'phone' => $profile['phone'] ?? null,
                'country_code' => strtoupper($profile['country_code']),
                'timezone' => $profile['timezone'],
                'currency' => strtoupper($profile['currency']),
                'locale' => $profile['locale'] ?? 'en',
            ]);
            $organization->forceFill([
                'slug' => $this->uniqueSlug($profile['slug'] ?? null, $profile['name']),
                'status' => $status,
                'status_changed_at' => now(),
                'created_by_user_id' => $actor?->id ?? $owner?->id,
            ])->save();

            $history = new OrganizationStatusHistory;
            $history->forceFill([
                'organization_id' => $organization->id,
                'from_status' => null,
                'to_status' => $status,
                'reason' => 'Organization created',
                'actor_user_id' => $actor?->id ?? $owner?->id,
                'occurred_at' => now(),
            ])->save();

            $result = $this->tenant->runAs($organization, function () use ($organization, $owner, $ownerEmail, $actor) {
                $roles = $this->createDefaultRoles($organization);
                $this->settings->seedOrganization($organization, $this->regionalDefaults($organization->country_code));

                $membership = new OrganizationMembership;
                $token = null;
                if ($owner !== null) {
                    $membership->forceFill([
                        'user_id' => $owner->id,
                        'status' => MembershipStatus::Active,
                        'is_provider' => true,
                        'joined_at' => now(),
                    ])->save();
                } else {
                    $token = InvitationTokens::generate();
                    $membership->forceFill([
                        'invited_email' => mb_strtolower(trim($ownerEmail)),
                        'status' => MembershipStatus::Invited,
                        'is_provider' => true,
                        'invitation_token_hash' => InvitationTokens::hash($token),
                        'invitation_expires_at' => now()->addDays(InvitationTokens::VALID_DAYS),
                        'invited_by_user_id' => $actor?->id,
                    ])->save();
                }
                $membership->roles()->attach($roles[RoleTemplates::ORG_ADMIN]->id);

                return [$membership, $token];
            });

            [$membership, $invitationToken] = $result;

            $subscription = ($this->startSubscription)($organization, $plan, trial: $status === OrganizationStatus::Trial, actor: $actor ?? $owner);

            $this->audit->record(
                'organization.created',
                subject: $organization,
                after: ['name' => $organization->name, 'slug' => $organization->slug, 'status' => $status->value, 'plan' => $plan->key],
                summary: "Organization {$organization->name} created",
                context: $auditContext,
            );

            OrganizationCreated::dispatch($organization, $actor?->id ?? $owner?->id);

            return new CreatedOrganization($organization, $membership, $subscription, $invitationToken);
        });
    }

    /** @return array<string, Role> template key => role */
    private function createDefaultRoles(Organization $organization): array
    {
        $roles = [];
        foreach (RoleTemplates::organization() as $key => $template) {
            $role = new Role;
            $role->forceFill([
                'organization_id' => $organization->id,
                'scope' => Role::SCOPE_ORGANIZATION,
                'key' => $key,
                'name' => $template['name'],
                'description' => $template['description'],
                'is_system' => true,
                'is_locked' => $template['locked'],
            ])->save();

            SyncPermissionCatalogue::grant($role, RoleTemplates::resolve($template, Role::SCOPE_ORGANIZATION));
            $roles[$key] = $role;
        }

        return $roles;
    }

    /** @return array<string, mixed> */
    private function regionalDefaults(string $countryCode): array
    {
        return match ($countryCode) {
            'US' => ['general.date_format' => 'm/d/Y', 'general.time_format' => 'g:i A', 'general.week_starts_on' => '7'],
            'CA', 'PH' => ['general.date_format' => 'Y-m-d', 'general.time_format' => 'g:i A', 'general.week_starts_on' => '7'],
            default => ['general.date_format' => 'd/m/Y', 'general.time_format' => 'H:i', 'general.week_starts_on' => '1'],
        };
    }

    private function uniqueSlug(?string $requested, string $name): string
    {
        $base = Str::slug($requested ?: $name);
        $base = trim(substr($base, 0, 50), '-') ?: 'practice';

        if ($requested !== null && $requested !== '' && $base !== $requested) {
            throw new DomainException('The address may only contain lowercase letters, numbers and hyphens.', 'invalid_slug', 'slug');
        }

        $taken = fn (string $slug) => in_array($slug, self::RESERVED_SLUGS, true)
            || Organization::query()->where('slug', $slug)->exists();

        if (! $taken($base)) {
            return $base;
        }

        if ($requested !== null && $requested !== '') {
            throw new DomainException('That address is already taken.', 'slug_taken', 'slug');
        }

        do {
            $candidate = $base.'-'.Str::lower(Str::random(4));
        } while ($taken($candidate));

        return $candidate;
    }
}
