<?php

namespace Tests\Feature\Settings\Http;

use App\Models\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every settings route is closed to a member who lacks its permission, and opens when the permission is
 * granted: the boundary is proven by taking the permission away from the very role that holds it.
 */
class SettingsAccessTest extends SettingsHttpTestCase
{
    /** @return array<string, array{0: string, 1: string, 2: string, 3: list<string>}> method, path template, permission, extra permissions needed */
    public static function routes(): array
    {
        $o = 'organization.settings.manage';
        $staff = \App\Domain\Identity\RoleTemplates::organization()['staff']['permissions'];

        return [
            'organization page' => ['GET', 'organization', $o, []],
            'organization update' => ['PUT', 'organization', $o, []],
            'logo upload' => ['POST', 'organization/logo', $o, []],
            'locations list' => ['GET', 'locations', 'locations.manage', []],
            'location create form' => ['GET', 'locations/create', 'locations.manage', []],
            'location store' => ['POST', 'locations', 'locations.manage', []],
            'location edit' => ['GET', 'locations/{location}/edit', 'locations.manage', []],
            'location update' => ['PUT', 'locations/{location}', 'locations.manage', []],
            'location status' => ['PATCH', 'locations/{location}/status', 'locations.manage', []],
            'services list' => ['GET', 'services', 'services.manage', []],
            'service create form' => ['GET', 'services/create', 'services.manage', []],
            'service store' => ['POST', 'services', 'services.manage', []],
            'service edit' => ['GET', 'services/{service}/edit', 'services.manage', []],
            'service update' => ['PUT', 'services/{service}', 'services.manage', []],
            'service status' => ['PATCH', 'services/{service}/status', 'services.manage', []],
            'team list' => ['GET', 'team', 'team.view', []],
            'team invite' => ['POST', 'team/invitations', 'team.manage', ['team.view']],
            // Managing someone requires holding everything their role grants (escalation guard),
            // so the actor also holds the whole "staff" template the target and invitation carry.
            'team resend' => ['POST', 'team/invitations/{invitation}/resend', 'team.manage', $staff],
            'team revoke' => ['DELETE', 'team/invitations/{invitation}', 'team.manage', $staff],
            'member edit page' => ['GET', 'team/{member}/edit', 'team.view', []],
            'member update' => ['PUT', 'team/{member}', 'team.manage', ['team.view']],
            'member status' => ['PATCH', 'team/{member}/status', 'team.manage', $staff],
            'roles list' => ['GET', 'roles', 'roles.manage', []],
            'role create form' => ['GET', 'roles/create', 'roles.manage', []],
            'role store' => ['POST', 'roles', 'roles.manage', []],
            'role edit' => ['GET', 'roles/{role}/edit', 'roles.manage', []],
            'role update' => ['PUT', 'roles/{role}', 'roles.manage', []],
            'role delete' => ['DELETE', 'roles/{role}', 'roles.manage', []],
            'scheduling page' => ['GET', 'scheduling', $o, []],
            'scheduling update' => ['PUT', 'scheduling', $o, []],
            'client settings page' => ['GET', 'clients', $o, []],
            'client settings update' => ['PUT', 'clients', $o, []],
            'audit log' => ['GET', 'audit', 'audit.view', []],
            'subscription' => ['GET', 'subscription', $o, []],
        ];
    }

    /** @param list<string> $extra */
    #[Test]
    #[DataProvider('routes')]
    public function a_route_needs_its_permission(string $method, string $template, string $permission, array $extra): void
    {
        $location = $this->location();
        $service = $this->service();
        [$invitation] = $this->invitation('someone@example.com');
        $colleague = $this->addStaff($this->org, 'staff');
        $uriFor = function () use ($template, $location, $service, &$invitation, $colleague) {
            return $this->path(strtr($template, [
            '{location}' => $location->id, '{service}' => $service->id, '{invitation}' => $invitation->id,
            '{member}' => $colleague->id, '{role}' => $this->makeRole($this->org, 'Spare '.fake()->unique()->numerify('####'), ['clients.view'])->id,
            ]));
        };
        $payload = $method === 'PATCH' ? ['active' => '0', 'to' => 'suspended'] : [];

        [$member, $role] = $this->memberWith([$permission, ...$extra]);

        $allowed = $this->call($method, $uriFor(), $payload + ['_token' => csrf_token()])->getStatusCode();
        $this->assertNotContains($allowed, [403, 404], "{$method} {$template} should be open to someone holding {$permission} (got {$allowed})");

        if (str_contains($template, '{invitation}')) { // the allowed call may have withdrawn it
            [$invitation] = $this->invitation('again@example.com');
            $this->actingAs(\App\Models\User::query()->findOrFail($member->user_id));
        }

        $this->revokeFromRole($this->org, $role->key, $permission);

        $this->call($method, $uriFor(), $payload + ['_token' => csrf_token()])->assertForbidden();
    }

    #[Test]
    public function a_stranger_to_the_organization_is_turned_away_from_every_section(): void
    {
        $this->asOtherAdmin();

        foreach (['organization', 'locations', 'services', 'team', 'roles', 'scheduling', 'clients', 'audit', 'subscription', ''] as $section) {
            $status = $this->get($this->path($section))->getStatusCode();
            $this->assertContains($status, [403, 404], "/{$section} must not open for a member of another organization");
        }
    }

    #[Test]
    public function the_settings_home_opens_the_first_section_the_member_may_use(): void
    {
        $this->asAdmin()->get($this->path())->assertRedirect($this->path('organization'));

        $this->memberWith(['team.view']);
        $this->get($this->path())->assertRedirect($this->path('team'));

        $this->memberWith(['clients.view']);
        $this->get($this->path())->assertForbidden();
    }

    #[Test]
    public function the_section_list_shows_only_what_the_member_may_open(): void
    {
        $this->memberWith(['team.view', 'audit.view']);

        $page = $this->get($this->path('team'))->assertOk();
        $page->assertSee('Audit log')->assertSee('Team');
        $page->assertDontSee('Subscription &amp; usage', false)->assertDontSee('>Locations<', false)->assertDontSee('Roles &amp; Permissions', false);

        $this->asAdmin()->get($this->path('team'))->assertSee('Subscription &amp; usage', false)->assertSee('Roles &amp; Permissions', false);
    }

    #[Test]
    public function an_entitlement_is_needed_as_well_as_the_permission(): void
    {
        $this->actAs($this->admin, fn () => app(\App\Domain\Saas\EntitlementService::class)->flush());
        \Illuminate\Support\Facades\DB::table('plan_features')->where('feature_key', \App\Domain\Saas\FeatureRegistry::CALENDAR)->update(['enabled' => false]);
        app(\App\Domain\Saas\EntitlementService::class)->flush();

        $this->asAdmin()->get($this->path('scheduling'))->assertStatus(403);
        $this->get($this->path('organization'))->assertOk()->assertDontSee('>Scheduling<', false);
    }
}
