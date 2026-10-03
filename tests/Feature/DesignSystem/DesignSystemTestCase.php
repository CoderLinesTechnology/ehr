<?php

namespace Tests\Feature\DesignSystem;

use App\View\ShellComposer;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;

/**
 * Base for the design-system tests. Deliberately NOT Tests\TestCase: these tests render views and need no
 * database, so they must not pull in RefreshDatabase and the catalogue seeder.
 */
abstract class DesignSystemTestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The real composer reads platform settings from the database. Layouts are tested against a stub that
        // injects a known $shell (or none, like an error page rendered without one).
        $this->useShell(null);
    }

    /** Make the layouts' $shell composer provide exactly this array (null: provide nothing). */
    protected function useShell(?array $shell): void
    {
        $this->app->instance(ShellComposer::class, new class($shell)
        {
            public function __construct(private readonly ?array $shell) {}

            public function compose($view): void
            {
                if ($this->shell !== null) {
                    $view->with('shell', $this->shell);
                }
            }
        });
    }

    /** @param  array<string, mixed>  $overrides */
    protected function sampleShell(array $overrides = []): array
    {
        $item = static fn (string $key, string $label, string $icon, bool $active = false, ?int $badge = null): array => [
            'key' => $key, 'label' => $label, 'icon' => $icon, 'url' => 'https://app.test/'.$key, 'active' => $active, 'badge' => $badge,
        ];

        return array_replace([
            'platformName' => 'WellNest',
            'announcement' => null,
            'legal' => ['termsUrl' => null, 'privacyUrl' => null],
            'user' => ['name' => 'Avery Mensah', 'email' => 'avery@example.org', 'initials' => 'AM'],
            'organization' => ['name' => 'Harbor Light', 'slug' => 'harbor-light', 'logoUrl' => null, 'isDemoDataPresent' => false],
            'organizations' => [],
            'nav' => [$item('dashboard', 'Dashboard', 'house', true), $item('clients', 'Clients', 'users'), $item('messages', 'Messages', 'message-circle', false, 3)],
            'secondaryNav' => [$item('settings', 'Settings', 'settings')],
            'searchUrl' => 'https://app.test/search',
            'accountUrl' => 'https://app.test/account',
            'logoutUrl' => 'https://app.test/logout',
            'platformUrl' => null,
            'appUrl' => null,
        ], $overrides);
    }

    protected function html(string $template, array $data = []): string
    {
        return (string) $this->blade($template, $data);
    }

    protected function dom(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    /** @return list<string> the matching nodes' attribute values (or text when $attribute is null) */
    protected function find(string $html, string $xpath, ?string $attribute = null): array
    {
        $out = [];
        foreach ($this->dom($html)->query($xpath) ?: [] as $node) {
            $out[] = $attribute === null ? trim(preg_replace('/\s+/', ' ', $node->textContent)) : $node->getAttribute($attribute);
        }

        return $out;
    }

    protected function assertFound(string $html, string $xpath, string $message = ''): void
    {
        $this->assertNotEmpty($this->dom($html)->query($xpath)?->length, $message ?: "Expected to find {$xpath}");
    }

    protected function assertMissing(string $html, string $xpath, string $message = ''): void
    {
        $this->assertSame(0, $this->dom($html)->query($xpath)?->length, $message ?: "Expected NOT to find {$xpath}");
    }

    /** Make old('field') return these values, as after a redirect-back with input. */
    protected function withOldInput(array $input): void
    {
        $request = Request::create('/');
        $request->setLaravelSession($this->app['session.store']);
        $this->app['session.store']->put('_old_input', $input);
        $this->app->instance('request', $request);
    }

    protected function assertNoBladeLeak(string $html, string $what = 'output'): void
    {
        $this->assertStringNotContainsString('{{--', $html, "A Blade comment leaked into the {$what}");
        $this->assertStringNotContainsString('--}}', $html, "A Blade comment terminator leaked into the {$what}");
        $this->assertStringNotContainsString('{{', $html, "An unrendered Blade echo leaked into the {$what}");
        $this->assertStringNotContainsString('@props', $html, "A directive leaked into the {$what}");
    }
}
