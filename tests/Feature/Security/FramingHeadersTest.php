<?php

namespace Tests\Feature\Security;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who may frame WellNest's pages (clickjacking). Staff-app pages (/o/{organization}/…, routes `app.*`) may be framed
 * by WellNest itself only — the telehealth call page shows them in its app frame while a call floats. Everything
 * else refuses every frame: sign-in, account, the platform console, webhooks, responses without a route.
 */
class FramingHeadersTest extends TestCase
{
    private function assertFrameableBySelfOnly(TestResponse $response, string $what): void
    {
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'), $what);
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'self'", $csp, $what);
        $this->assertStringNotContainsString("frame-ancestors 'none'", $csp, $what);
    }

    private function assertNeverFramed(TestResponse $response, string $what): void
    {
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'), $what);
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp, $what);
        $this->assertStringNotContainsString("frame-ancestors 'self'", $csp, $what);
    }

    #[Test]
    public function staff_app_pages_may_be_framed_by_wellnest_only(): void
    {
        $created = $this->createOrganization();
        $owner = $created->ownerMembership->user()->first();
        $slug = $created->organization->slug;
        $this->actingAs($owner);

        foreach ([route('app.dashboard', ['organization' => $slug]), route('app.clients.index', ['organization' => $slug])] as $url) {
            $this->assertFrameableBySelfOnly($this->get($url)->assertOk(), $url);
        }

        // An error inside the app is still an app page (it shows in the frame instead of a browser error).
        $this->assertFrameableBySelfOnly($this->get(route('app.clients.show', ['organization' => $slug, 'client' => '00000000-0000-0000-0000-000000000000']))->assertNotFound(), 'app 404');

        // Only the call page frames anything: no other app page gets a frame-src.
        $this->assertStringNotContainsString('frame-src', (string) $this->get(route('app.dashboard', ['organization' => $slug]))->headers->get('Content-Security-Policy'));
    }

    #[Test]
    public function sign_in_account_platform_webhooks_and_unrouted_responses_are_never_framed(): void
    {
        $this->assertNeverFramed($this->get(route('login'))->assertOk(), 'sign-in');
        $this->assertNeverFramed($this->get('/')->assertRedirect(route('login')), 'root');
        $this->assertNeverFramed($this->post(route('webhooks.daily'), []), 'Daily webhook');
        $this->assertNeverFramed($this->get('/o/nowhere-at-all/clients/x/y/z')->assertNotFound(), 'no route');

        $created = $this->createOrganization();
        $this->actingAs($created->ownerMembership->user()->first());
        $this->assertNeverFramed($this->get(route('account.profile'))->assertOk(), 'account');
        $this->assertNeverFramed($this->get(route('organizations.choose')), 'organization chooser');

        $this->actingAsPlatformUser($this->platformUser());
        $this->assertNeverFramed($this->get(route('platform.dashboard'))->assertOk(), 'platform console');
    }
}
