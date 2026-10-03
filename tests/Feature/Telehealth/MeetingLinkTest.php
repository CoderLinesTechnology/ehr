<?php

namespace Tests\Feature\Telehealth;

use App\Domain\Shared\DomainException;
use App\Domain\Telehealth\Providers\ExternalLinkProvider;
use App\Domain\Telehealth\Providers\MeetingLinkPolicy;
use App\Domain\Telehealth\Providers\ProviderRegistry;
use App\Domain\Telehealth\SetMeetingLink;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/** The external-meeting-link provider: https only, host allowlist, encrypted at rest, never audited. */
class MeetingLinkTest extends TelehealthTestCase
{
    /** @return iterable<string, array{string}> */
    public static function acceptable(): iterable
    {
        yield 'zoom apex' => ['https://zoom.us/j/123456789'];
        yield 'zoom sub-domain with passcode' => ['https://us02web.zoom.us/j/123?pwd=abc'];
        yield 'zoom upper-case host' => ['https://Acme.ZOOM.us/j/1'];
        yield 'google meet' => ['https://meet.google.com/abc-defg-hij'];
        yield 'teams' => ['https://teams.microsoft.com/l/meetup-join/19%3ameeting'];
        yield 'explicit https port' => ['https://zoom.us:443/j/1'];
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'plain http' => ['http://zoom.us/j/1'];
        yield 'no scheme' => ['zoom.us/j/1'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'data scheme' => ['data:text/html,<script>1</script>'];
        yield 'lookalike suffix' => ['https://zoom.us.evil.example/j/1'];
        yield 'lookalike prefix' => ['https://evilzoom.us/j/1'];
        yield 'credentials in the url' => ['https://zoom.us@evil.example/j/1'];
        yield 'userinfo with the real host' => ['https://user:pw@zoom.us/j/1'];
        yield 'odd port' => ['https://zoom.us:8443/j/1'];
        yield 'host not on the list' => ['https://example.com/meeting'];
        yield 'ip address' => ['https://203.0.113.9/j/1'];
        yield 'space inside' => ['https://zoom.us/j/1 2'];
        yield 'newline inside' => ["https://zoom.us/j/1\nhttps://evil.example"];
        yield 'empty' => [''];
        yield 'too long' => ['https://zoom.us/'.'a'];
    }

    #[Test]
    #[DataProvider('acceptable')]
    public function acceptable_links_are_stored_trimmed_and_unchanged(string $url): void
    {
        $session = $this->sessionAt('2026-10-06 10:00:00', link: null);

        app(SetMeetingLink::class)($session, "  {$url}  ", $this->actor);

        $this->assertSame($url, $session->refresh()->join_url);
    }

    #[Test]
    #[DataProvider('refused')]
    public function every_other_link_is_refused_and_nothing_is_stored(string $url): void
    {
        $session = $this->sessionAt('2026-10-06 10:00:00', link: null);
        if ($url === 'https://zoom.us/a') {
            $url = 'https://zoom.us/'.str_repeat('a', MeetingLinkPolicy::MAX_LENGTH);
        }

        try {
            app(SetMeetingLink::class)($session, $url, $this->actor);
            $this->fail('A link that should have been refused was stored.');
        } catch (DomainException $e) {
            $this->assertSame('join_url', $e->field());
        }

        $this->assertNull($session->refresh()->join_url);
    }

    #[Test]
    public function the_allowlist_is_configurable_per_organization(): void
    {
        $session = $this->sessionAt('2026-10-06 10:00:00', link: null);
        $this->setting('telehealth.allowed_hosts', "*.whereby.com\n*.vendor-video.example");

        app(SetMeetingLink::class)($session, 'https://acme.whereby.com/room', $this->actor);
        $this->assertSame('https://acme.whereby.com/room', $session->refresh()->join_url);

        $this->expectException(DomainException::class);
        app(SetMeetingLink::class)($session, 'https://zoom.us/j/1', $this->actor);
    }

    #[Test]
    public function a_wildcard_pattern_matches_sub_domains_only_not_the_apex_or_a_longer_name(): void
    {
        $this->assertTrue(MeetingLinkPolicy::validPattern('*.zoom.us'));
        foreach (['*', '*.com', 'zoom', '*zoom.us', 'zoom.us/path', 'zoom.us:443', 'https://zoom.us', '.zoom.us', 'a b.com'] as $bad) {
            $this->assertFalse(MeetingLinkPolicy::validPattern($bad), $bad);
        }

        $session = $this->sessionAt('2026-10-06 10:00:00', link: null);
        $this->setting('telehealth.allowed_hosts', '*.zoom.us');
        $this->expectException(DomainException::class);
        app(SetMeetingLink::class)($session, 'https://zoom.us/j/1', $this->actor); // the apex is not a sub-domain
    }

    #[Test]
    public function tightening_the_allowlist_stops_links_that_are_already_stored_from_being_offered(): void
    {
        $session = $this->sessionAt('2026-10-06 10:00:00');
        $provider = app(ProviderRegistry::class)->get($session->provider_key);
        $this->assertSame(self::LINK, $provider->joinUrlFor($session, $this->actor));

        $this->setting('telehealth.allowed_hosts', 'meet.google.com');

        $this->assertNull($provider->joinUrlFor($session->refresh(), $this->actor));
    }

    #[Test]
    public function the_link_is_encrypted_at_rest_and_never_written_to_the_audit_log(): void
    {
        $session = $this->sessionAt('2026-10-06 10:00:00');

        $raw = (string) DB::table('telehealth_sessions')->where('id', $session->id)->value('join_url');
        $this->assertNotSame(self::LINK, $raw);
        $this->assertStringNotContainsString('zoom.us', $raw);
        $this->assertStringNotContainsString('Secret123', $raw);

        $audit = json_encode(DB::table('audit_logs')->get());
        $this->assertStringNotContainsString('Secret123', $audit);
        $this->assertStringNotContainsString('zoom.us/j', $audit);
        $this->assertStringNotContainsString('join_url', json_encode($session->toArray()), 'the link is a hidden attribute');
    }

    #[Test]
    public function the_default_link_setting_is_a_secret_whose_audit_entry_is_redacted(): void
    {
        $this->setting('telehealth.default_link_secret', 'https://zoom.us/j/777?pwd=TopSecret');

        $this->assertStringNotContainsString('TopSecret', json_encode(DB::table('audit_logs')->get()));
        $this->assertStringNotContainsString('TopSecret', (string) DB::table('organization_settings')->where('key', 'telehealth.default_link_secret')->value('value'));
    }

    #[Test]
    public function the_provider_declares_its_capabilities_and_makes_no_outbound_call(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        $provider = app(ProviderRegistry::class)->default();

        $this->assertInstanceOf(ExternalLinkProvider::class, $provider);
        $this->assertSame('external_link', $provider->key());
        $this->assertTrue($provider->capabilities()->recording);
        $this->assertFalse($provider->capabilities()->waitingRoom);

        $this->sessionAt('2026-10-06 10:00:00');
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }
}
