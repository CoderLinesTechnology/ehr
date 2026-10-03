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

    /** @return list<string> */
    private function labels(string $html): array
    {
        preg_match_all('#<span class="nav-link__label">([^<]*)</span>#', $html, $m);

        return array_map('html_entity_decode', $m[1]);
    }
}
