<?php

namespace Tests\Feature\DesignSystem;

class LayoutsTest extends DesignSystemTestCase
{
    public function test_app_layout_renders_shell_with_lucide_nav_and_active_state(): void
    {
        $this->useShell($this->sampleShell());
        $html = $this->html('<x-layouts.app title="Clients"><p>Body</p></x-layouts.app>');

        $this->assertFound($html, '//aside[contains(@class,"sidebar")]//a[contains(@class,"nav-link")][@aria-current="page"]');
        $this->assertSame(['Dashboard', 'Clients', 'Messages Unread: 3', 'Settings'], $this->find($html, '//aside//a[contains(@class,"nav-link")]'));
        $this->assertFound($html, '//aside//a[contains(@class,"nav-link")]//svg[@stroke="currentColor"]');
        $this->assertStringContainsString('Better care.', $html);
        $this->assertStringContainsString('Healthier tomorrows.', $html);
        $this->assertFound($html, '//header[contains(@class,"topbar")]//form[@role="search"]');
        $this->assertSame(['Avery Mensah'], $this->find($html, '//*[contains(@class,"user-menu__name")]'));
        $this->assertNoBladeLeak($html);
    }

    public function test_bell_dot_only_with_unread_and_search_hidden_without_url(): void
    {
        $this->useShell($this->sampleShell(['searchUrl' => null]));
        $html = $this->html('<x-layouts.app title="X">x</x-layouts.app>');
        $this->assertMissing($html, '//*[contains(@class,"notifications__dot")]');
        $this->assertMissing($html, '//form[@role="search"]');

        $this->useShell($this->sampleShell(['unreadNotifications' => 3]));
        $this->assertFound($this->html('<x-layouts.app title="X">x</x-layouts.app>'), '//*[contains(@class,"notifications__dot")]');
    }

    public function test_platform_layout_uses_dark_sidebar_marker(): void
    {
        $this->useShell($this->sampleShell(['organization' => null]));
        $html = $this->html('<x-layouts.platform title="Orgs">x</x-layouts.platform>');
        $this->assertFound($html, '//body[@data-shell="platform"]');
        $this->assertStringContainsString('Super Admin console', $html);
    }

    public function test_phone_tab_bar_has_four_entries(): void
    {
        $this->useShell($this->sampleShell());
        $html = $this->html('<x-layouts.app title="X">x</x-layouts.app>');
        $this->assertSame(4, count($this->find($html, '//nav[contains(@class,"tabbar")]/*')));
    }

    public function test_auth_layout_has_logo_and_login_view_has_no_remember_me(): void
    {
        $this->useShell(null);
        $html = $this->html('<x-layouts.auth title="Sign in">x</x-layouts.auth>');
        $this->assertFound($html, '//img[contains(@src,"wellnest-mark.svg")]');

        $login = file_get_contents(resource_path('views/auth/login.blade.php'));
        $this->assertStringNotContainsStringIgnoringCase('remember', $login);
    }

    public function test_no_cdn_or_inline_script_in_layouts(): void
    {
        $this->useShell($this->sampleShell());
        $html = $this->html('<x-layouts.app title="X">x</x-layouts.app>');
        $this->assertDoesNotMatchRegularExpression('#(?:src|href)="https?://(?!app\.test|localhost)#', $html);
        $this->assertSame(0, preg_match('#<script(?![^>]*\bsrc=)[^>]*>#i', $html));
    }
}
