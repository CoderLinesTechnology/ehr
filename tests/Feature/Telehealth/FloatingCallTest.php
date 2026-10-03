<?php

namespace Tests\Feature\Telehealth;

use App\Domain\Telehealth\OpenSession;
use App\Http\Requests\Telehealth\CallPageRequest;
use App\Models\TelehealthSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The call page as a "call host": the call keeps running in a floating window while the user browses the app in the
 * page's own same-origin frame (spec docs/design/spec/screens/12-telehealth-call.md). The behaviour itself is the
 * script's (public/js/screens/telehealth-call.js, checked in a browser); this pins what the server owns: the markup, the
 * stub that stops a call from nesting, and the `?app=` page a refresh restores.
 */
class FloatingCallTest extends TelehealthTestCase
{
    private function running(): TelehealthSession
    {
        $session = $this->sessionAt('2026-10-02 08:05:00');
        app(OpenSession::class)($session, $this->actor);

        return $session->refresh();
    }

    private function path(string $route, TelehealthSession $session): string
    {
        return (string) parse_url($this->url($route, ['session' => $session->id]), PHP_URL_PATH);
    }

    #[Test]
    public function the_call_page_hosts_a_named_app_frame_and_a_dock_whose_window_controls_are_buttons(): void
    {
        $session = $this->running();
        $html = $this->as($this->drA)->get($this->callUrl($session))->assertOk()->getContent();
        $slug = $this->organization->slug;

        // The host marker the scripts (telehealth-call.js, and app.js inside the frame) rely on.
        $this->assertStringContainsString('data-call-host="/o/'.$slug.'"', $html);
        $this->assertStringContainsString('data-call-path="'.$this->path('app.telehealth.call', $session).'"', $html);
        $this->assertStringContainsString('data-call-join="'.$this->path('app.telehealth.join', $session).'"', $html);
        $this->assertStringNotContainsString('data-call-app=', $html, 'nothing to restore without ?app=');
        // Both scripts: the copy buttons (telehealth.js) and the call host, which only this page loads.
        $this->assertStringContainsString('js/screens/telehealth.js', $html);
        $this->assertStringContainsString('js/screens/telehealth-call.js', $html);

        // The app frame: named, hidden until a link opens in it, never given camera, microphone or screen capture.
        $this->assertSame(1, preg_match('/<iframe name="wellnest-app" class="tv-appframe"[^>]*>/', $html, $frame));
        $this->assertStringContainsString(' hidden', $frame[0]);
        $this->assertStringContainsString('title="WellNest"', $frame[0]);
        $this->assertStringContainsString("allow=\"camera 'none'; microphone 'none'; display-capture 'none'\"", $frame[0]);
        $this->assertStringNotContainsString(' src=', $frame[0]);

        // The dock is a labelled region holding the bar, the one Daily frame (unchanged attributes) and a live region.
        $this->assertSame(1, preg_match('/<div class="tv-dock" role="region" aria-label="Video call with Alice Alpha" data-call-dock>(.*?)<\/iframe>\s*<p class="sr-only" role="status" data-call-status><\/p>\s*<\/div>/s', $html, $dock));
        $this->assertSame(1, substr_count($html, '<iframe class="tv-frame"'));
        $this->assertStringContainsString('<iframe class="tv-frame" src="', $dock[1]);
        $this->assertStringContainsString('allow="camera; microphone; autoplay; display-capture; fullscreen" allowfullscreen referrerpolicy="no-referrer"', $dock[1]);
        foreach (['Expand call', 'Full screen', 'Move to next corner', 'Leave call'] as $label) {
            $this->assertMatchesRegularExpression('/<button type="button" class="tv-bar__btn[^"]*"[^>]*aria-label="'.$label.'"/', $dock[1], $label);
        }
        $this->assertStringContainsString('<span class="tv-bar__name">Alice Alpha</span>', $dock[1]);

        // Full screen in the header too: a toggle, revealed only where the browser allows it.
        $this->assertMatchesRegularExpression('/<button type="button" class="tj-btn tj-btn--outline tv-head__fs" data-call-fullscreen aria-pressed="false" title="Full screen" hidden>/', $html);
        $this->assertStringContainsString('id="tv-title" tabindex="-1"', $html);

        // Still no inline script and no inline handler anywhere.
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<[a-z][^>]*\son[a-z]+\s*=/i', $html);
    }

    #[Test]
    public function without_a_call_the_page_is_no_host(): void
    {
        $session = $this->running();
        $this->videoNotConfigured();

        $html = $this->as($this->drA)->get($this->callUrl($session).'?app='.urlencode('/o/'.$this->organization->slug.'/clients'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-call-host', $html);
        $this->assertStringNotContainsString('wellnest-app', $html);
        $this->assertStringNotContainsString('data-call-dock', $html);
        $this->assertStringNotContainsString('data-call-app=', $html);
    }

    #[Test]
    public function opened_inside_a_frame_the_call_page_is_a_stub_that_mints_no_pass(): void
    {
        $session = $this->running();
        $operations = $this->dailyOperations();

        foreach (['iframe', 'frame'] as $dest) {
            $response = $this->as($this->drA)->withHeaders(['Sec-Fetch-Dest' => $dest])->get($this->callUrl($session))->assertOk();
            $html = $response->getContent();

            $this->assertStringContainsString('This call is already open', $html, $dest);
            $this->assertStringContainsString('data-call-open="'.$this->path('app.telehealth.call', $session).'"', $html);
            $this->assertStringContainsString('target="_top"', $html);
            $this->assertStringNotContainsString('<iframe', $html, 'no Daily frame, no app frame: a call never nests');
            $this->assertStringNotContainsString('data-call-host', $html);
            $this->assertStringNotContainsString('fake-token-', $html);
            $this->assertStringContainsString('js/screens/telehealth-call.js', $html);
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html);
            $this->assertStringContainsString("frame-ancestors 'self'", (string) $response->headers->get('Content-Security-Policy'));
        }

        $this->assertSame($operations, $this->dailyOperations(), 'the stub never reaches Daily');
        $this->assertSame([], $this->daily->callsOf('meeting-tokens.create'), 'no pass is minted for a framed request');

        // The same page in its own tab still connects.
        $this->withHeaders(['Sec-Fetch-Dest' => 'document'])->get($this->callUrl($session))->assertOk()->assertSee('<iframe class="tv-frame"', false);
        $this->assertCount(1, $this->daily->callsOf('meeting-tokens.create'));
    }

    #[Test]
    public function the_stub_costs_no_more_queries_than_the_call_page_and_the_restored_page_none_at_all(): void
    {
        $session = $this->running();
        $this->as($this->drA)->get($this->callUrl($session))->assertOk();   // warm the memos

        $call = $this->queryCount(fn () => $this->get($this->callUrl($session))->assertOk());
        $restore = $this->queryCount(fn () => $this->get($this->callUrl($session).'?app='.urlencode('/o/'.$this->organization->slug.'/clients'))->assertOk());
        $stub = $this->queryCount(fn () => $this->withHeaders(['Sec-Fetch-Dest' => 'iframe'])->get($this->callUrl($session))->assertOk());

        $this->assertSame($call, $restore, '?app= is validated without the database');
        $this->assertLessThan($call, $stub, 'the stub skips the details, the pass and the settings');
    }

    #[Test]
    public function a_framed_request_for_a_session_that_is_not_running_still_goes_to_its_page(): void
    {
        $upcoming = $this->sessionAt('2026-10-06 10:00:00');

        $this->as($this->drA)->withHeaders(['Sec-Fetch-Dest' => 'iframe'])->get($this->callUrl($upcoming))->assertRedirect($this->joinUrl($upcoming));
        $this->assertSame([], $this->daily->callsOf('meeting-tokens.create'));
    }

    /** @return array<string, array{0: string}> */
    public static function pagesInsideTheApp(): array
    {
        return [
            'a list' => ['/o/{slug}/clients'],
            'with a query' => ['/o/{slug}/calendar?view=week&date=2026-10-02'],
            'the dashboard' => ['/o/{slug}'],
            'percent-encoded query' => ['/o/{slug}/clients?q=Alice%20Alpha'],
        ];
    }

    #[Test]
    #[DataProvider('pagesInsideTheApp')]
    public function a_page_inside_this_organizations_app_is_reopened_in_the_frame_after_a_refresh(string $app): void
    {
        $session = $this->running();
        $app = str_replace('{slug}', $this->organization->slug, $app);

        $html = $this->as($this->drA)->get($this->callUrl($session).'?app='.urlencode($app))->assertOk()->getContent();

        $this->assertStringContainsString('data-call-app="'.e($app).'"', $html);
    }

    /** @return array<string, array{0: mixed}> */
    public static function pagesThatAreIgnored(): array
    {
        return [
            'protocol-relative host' => ['//evil.example/o/{slug}/clients'],
            'absolute URL' => ['https://evil.example/o/{slug}/clients'],
            'same host, absolute' => ['http://localhost/o/{slug}/clients'],
            'javascript' => ['javascript:alert(1)'],
            'another organization' => ['/o/{other}/clients'],
            'a slug prefix' => ['/o/{slug}x/clients'],
            'outside the app' => ['/platform'],
            'account' => ['/account'],
            'relative without slash' => ['o/{slug}/clients'],
            'double slash inside' => ['/o/{slug}//evil.example'],
            'backslash' => ['/o/{slug}/clients\\..\\..\\platform'],
            'backslash host' => ['/\\evil.example'],
            'CR LF' => ["/o/{slug}/clients\r\nSet-Cookie: x=1"],
            'tab' => ["/o/{slug}/clients\tx"],
            'NUL' => ["/o/{slug}/cli\0ents"],
            'encoded CR LF' => ['/o/{slug}/clients?q=%0d%0aX'],
            'dot segments' => ['/o/{slug}/../../platform'],
            'encoded dot segments' => ['/o/{slug}/%2e%2e/%2e%2e/platform'],
            'encoded slash' => ['/o/{slug}/%2f%2fevil.example'],
            'quotes and brackets' => ['/o/{slug}/clients"><img src=x>'],
            'fragment' => ['/o/{slug}/clients#top'],
            'space' => ['/o/{slug}/clients x'],
            'unicode' => ['/o/{slug}/clients/é'],
            'overlong' => ['/o/{slug}/clients?q='.str_repeat('a', CallPageRequest::APP_PATH_MAX)],
            'empty' => [''],
            'an array' => [['/o/{slug}/clients']],
        ];
    }

    #[Test]
    #[DataProvider('pagesThatAreIgnored')]
    public function anything_else_in_app_is_ignored_never_reflected_and_never_an_error(mixed $app): void
    {
        $session = $this->running();
        $fill = fn (string $v): string => str_replace(['{slug}', '{other}'], [$this->organization->slug, $this->other->slug], $v);
        $app = is_array($app) ? array_map($fill, $app) : $fill($app);

        $html = $this->as($this->drA)->get($this->callUrl($session).'?'.http_build_query(['app' => $app]))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-call-app=', $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringContainsString('<iframe class="tv-frame"', $html, 'the call itself is unaffected');
    }
}
