<?php

namespace Tests\Feature\DesignSystem;

use App\Support\Icons;
use PHPUnit\Framework\Attributes\DataProvider;

class IconsTest extends DesignSystemTestCase
{
    public function test_a_known_icon_renders_real_lucide_markup(): void
    {
        $svg = Icons::svg('calendar', 24);

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('stroke="currentColor"', $svg);
        $this->assertStringContainsString('width="24"', $svg);
        $this->assertStringContainsString('aria-hidden="true"', $svg);
        $this->assertStringNotContainsString('role="img"', $svg);
        $this->assertStringContainsString('<path', $svg);
    }

    public function test_a_label_makes_the_icon_an_image_with_an_accessible_name(): void
    {
        $svg = Icons::svg('bell', 20, 'Notifications <b>');

        $this->assertStringContainsString('role="img"', $svg);
        $this->assertStringContainsString('aria-label="Notifications &lt;b&gt;"', $svg);
        $this->assertStringNotContainsString('aria-hidden', $svg);
    }

    #[DataProvider('badNames')]
    public function test_invalid_or_traversing_names_render_nothing(string $name): void
    {
        $this->assertSame('', Icons::svg($name));
        $this->assertFalse(Icons::exists($name));
    }

    public static function badNames(): array
    {
        return [['../../../.env'], ['..%2f..%2fcomposer'], ['House'], ['calendar.svg'], ['a/b'], ['calendar" onload="x'], ['does-not-exist'], ['']];
    }

    public function test_legacy_names_resolve_and_component_matches_class(): void
    {
        $this->assertTrue(Icons::exists('message'));
        $html = $this->html('<x-ui.icon name="users" :size="18" class="foo" />');
        $this->assertFound($html, '//svg[contains(@class,"icon") and contains(@class,"foo")][@width="18"]');
        $this->assertSame('', trim($this->html('<x-ui.icon name="nope" />')));
    }
}
