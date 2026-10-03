<?php

namespace Tests\Feature\DesignSystem;

use App\Models\User;
use App\View\ShellComposer;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** The real composer through a real request: labels, order, icons and route-existence filtering. */
class ShellNavTest extends TestCase
{
    public function test_nav_follows_spec_labels_order_icons_and_only_existing_routes(): void
    {
        $created = $this->createOrganization(['name' => 'Harbor Light']);
        $owner = User::query()->findOrFail($created->ownerMembership->user_id);
        $this->actingAs($owner);

        $html = $this->get(route('app.dashboard', ['organization' => $created->organization->slug]))->assertOk()->getContent();
        $labels = $this->labels($html);

        $this->assertSame('Dashboard', $labels[0]);
        $this->assertNotContains('Calendar', $labels, 'The calendar module is labelled "Appointments"');
        $this->assertSame(Route::has('app.calendar.index'), in_array('Appointments', $labels, true));
        $this->assertSame(Route::has('app.resources.index'), in_array('Resources', $labels, true));
        $this->assertSame(Route::has('app.messages.index'), in_array('Messages', $labels, true), 'never show an item whose route does not exist');
        $this->assertNotContains('Billing', $labels);

        $order = ['Dashboard', 'Clients', 'Appointments', 'Messages', 'Tasks', 'Documents', 'Resources', 'Telehealth', 'Programs', 'Reports', 'Settings'];
        $this->assertSame(array_values(array_intersect($order, $labels)), array_values(array_filter($labels, fn ($l) => in_array($l, $order, true))));

        // Lucide icons only, no unread dot (no notifications yet), role label present, mark image.
        $this->assertStringContainsString('stroke="currentColor"', $html);
        $this->assertStringNotContainsString('notifications__dot', $html);
        $this->assertStringContainsString('user-menu__role', $html);
        $this->assertStringContainsString('wellnest-mark.svg', $html);
    }

    public function test_shell_values_are_present_for_the_layouts(): void
    {
        $created = $this->createOrganization();
        $this->actingAs(User::query()->findOrFail($created->ownerMembership->user_id));
        $this->get(route('app.dashboard', ['organization' => $created->organization->slug]));

        $composer = $this->app->make(ShellComposer::class);
        $view = view('components.layouts.partials.head', ['pageTitle' => 'x']);
        $composer->compose($view);
        $shell = $view->getData()['shell'];

        foreach (['roleLabel', 'unreadNotifications', 'notifications', 'nav', 'secondaryNav', 'searchUrl'] as $key) {
            $this->assertArrayHasKey($key, $shell);
        }
        $this->assertSame(0, $shell['unreadNotifications']);
        foreach (array_merge($shell['nav'], $shell['secondaryNav']) as $item) {
            $this->assertFileExists(resource_path('icons/lucide/'.$item['icon'].'.svg'), $item['key']);
        }
    }

    public function test_account_pages_show_the_navigation_of_the_organization_last_worked_in(): void
    {
        $a = $this->createOrganization(['name' => 'Harbor Light']);
        $owner = User::query()->findOrFail($a->ownerMembership->user_id);
        $b = $this->createOrganization(['name' => 'Cedar Grove']);
        $this->addStaff($b->organization, 'receptionist', [], $owner);   // the same person, a narrower role in B
        $this->actingAs($owner);

        $this->get(route('app.dashboard', ['organization' => $b->organization->slug]))->assertOk();
        $html = $this->get(route('account.profile'))->assertOk()->getContent();
        $labels = $this->labels($html);
        $this->assertSame('Dashboard', $labels[0] ?? null, 'the account page keeps a full sidebar');
        $this->assertStringContainsString('href="'.route('app.dashboard', ['organization' => $b->organization->slug]).'"', $html, 'links go to the organization last worked in');
        $this->assertNotContains('Programs', $labels, 'built with the membership in THAT organization (a receptionist there: no programs.view)');
        $this->assertStringNotContainsString('href="'.route('app.dashboard', ['organization' => $a->organization->slug]).'" class="nav-link', $html);

        // Working in A again: the account pages follow (and A's owner sees its Settings).
        $this->get(route('app.dashboard', ['organization' => $a->organization->slug]))->assertOk();
        $html = $this->get(route('account.profile'))->assertOk()->getContent();
        $this->assertStringContainsString('href="'.route('app.dashboard', ['organization' => $a->organization->slug]).'"', $html);
        $this->assertContains('Programs', $this->labels($html), "A's owner holds programs.view there");
    }

    public function test_account_pages_use_the_only_organization_even_before_it_was_visited(): void
    {
        $created = $this->createOrganization(['name' => 'Harbor Light']);
        $this->actingAs(User::query()->findOrFail($created->ownerMembership->user_id));

        $html = $this->get(route('account.profile'))->assertOk()->getContent();
        $this->assertSame('Dashboard', $this->labels($html)[0] ?? null);
        $this->assertStringContainsString('href="'.route('app.dashboard', ['organization' => $created->organization->slug]).'"', $html);
    }

    public function test_a_membership_no_longer_active_is_never_borrowed_and_home_is_the_way_back(): void
    {
        $a = $this->createOrganization(['name' => 'Harbor Light']);
        $owner = User::query()->findOrFail($a->ownerMembership->user_id);
        $b = $this->createOrganization(['name' => 'Cedar Grove']);
        $inB = $this->addStaff($b->organization, 'clinician', [], $owner);
        $this->actingAs($owner);
        $this->get(route('app.dashboard', ['organization' => $b->organization->slug]))->assertOk();

        \Illuminate\Support\Facades\DB::table('organization_memberships')->where('id', $inB->id)->update(['status' => 'suspended']);
        \Illuminate\Support\Facades\DB::table('organization_memberships')->where('id', $a->ownerMembership->id)->update(['status' => 'deactivated']);

        $html = $this->get(route('account.profile'))->assertOk()->getContent();
        $this->assertSame(['Home'], $this->labels($html), 'no organization to borrow: one way back');
        $this->assertStringNotContainsString($b->organization->slug, $html);
        $this->assertStringContainsString('href="'.route('home').'"', $html);
    }

    public function test_with_several_organizations_and_none_worked_in_the_account_pages_offer_home_and_the_switcher(): void
    {
        $a = $this->createOrganization(['name' => 'Harbor Light']);
        $owner = User::query()->findOrFail($a->ownerMembership->user_id);
        $b = $this->createOrganization(['name' => 'Cedar Grove']);
        $this->addStaff($b->organization, 'clinician', [], $owner);
        $this->actingAs($owner);

        $html = $this->get(route('account.profile'))->assertOk()->getContent();
        $this->assertSame(['Home'], $this->labels($html));
        $this->assertStringContainsString('Switch organization', $html);
    }

    /** @return list<string> */
    private function labels(string $html): array
    {
        preg_match_all('#<span class="nav-link__label">([^<]*)</span>#', $html, $m);

        return array_map('html_entity_decode', $m[1]);
    }
}
