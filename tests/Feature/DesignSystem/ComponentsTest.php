<?php

namespace Tests\Feature\DesignSystem;

class ComponentsTest extends DesignSystemTestCase
{
    public function test_button_variants_sizes_and_link_mode(): void
    {
        foreach (['primary', 'secondary', 'tonal', 'neutral', 'success', 'warning', 'danger', 'info', 'ghost', 'link'] as $variant) {
            $this->assertFound($this->html('<x-ui.button variant="'.$variant.'">Go</x-ui.button>'), '//button[contains(@class,"btn--'.$variant.'")]');
        }
        $this->assertFound($this->html('<x-ui.button size="xs">Go</x-ui.button>'), '//button[contains(@class,"btn--xs")]');
        $this->assertFound($this->html('<x-ui.button href="/x" icon="plus">Go</x-ui.button>'), '//a[@href="/x"]//svg');
        $this->assertFound($this->html('<x-ui.button href="javascript:alert(1)">Go</x-ui.button>'), '//a[@href="#"]');
    }

    public function test_badge_avatar_and_stat(): void
    {
        $this->assertFound($this->html('<x-ui.badge tone="success">Active</x-ui.badge>'), '//span[contains(@class,"badge--success")]');
        $this->assertSame(['Active'], $this->find($this->html('<x-ui.badge tone="success">Active</x-ui.badge>'), '//span[contains(@class,"badge")]'), 'badge text must render (a glued @else{{ }} once swallowed it)');
        $this->assertSame(['Demo'], $this->find($this->html('<x-ui.badge tone="demo" />'), '//span[contains(@class,"badge")]'));
        $this->assertFound($this->html('<x-ui.badge tone="bogus">X</x-ui.badge>'), '//span[contains(@class,"badge--neutral")]');

        $initials = $this->html('<x-ui.avatar name="Sarah Carter" />');
        $this->assertSame(['SC'], $this->find($initials, '//span[@role="img"]'));
        $photo = $this->html('<x-ui.avatar name="Sarah Carter" src="/p.jpg" />');
        $this->assertFound($photo, '//img[@class="avatar__img"][@src="/p.jpg"]');
        $this->assertMissing($this->html('<x-ui.avatar name="X" src="javascript:alert(1)" />'), '//img');

        $stat = $this->html('<x-ui.stat label="Total Clients" value="48" icon="users" :trend="12" />');
        $this->assertStringContainsString('12%', $stat);
        $this->assertStringContainsString('vs. last 30 days', $stat);
        $down = $this->html('<x-ui.stat label="X" value="1" :trend="-5" />');
        $this->assertFound($down, '//span[contains(@class,"stat__delta--down")]');
        $this->assertStringContainsString('1 new', $this->html('<x-ui.stat label="X" value="1" alert="1 new" />'));
    }

    public function test_page_header_icon_tile_variants(): void
    {
        $circle = $this->html('<x-ui.page-header title="Clients" description="d" icon="users" />');
        $this->assertFound($circle, '//span[contains(@class,"icon-tile")][contains(@style,"56px")]');
        $square = $this->html('<x-ui.page-header title="Appointments" icon="calendar" icon-shape="square" />');
        $this->assertFound($square, '//span[contains(@class,"icon-tile--outlined")][contains(@style,"60px")]');
        $this->assertMissing($this->html('<x-ui.page-header title="Plain" />'), '//span[contains(@class,"icon-tile")]');
    }

    public function test_notifications_dot_only_when_unread(): void
    {
        $this->assertMissing($this->html('<x-ui.notifications :unread="0" />'), '//*[contains(@class,"notifications__dot")]');
        $this->assertFound($this->html('<x-ui.notifications :unread="2" />'), '//*[contains(@class,"notifications__dot")]');
        $this->assertStringContainsString('all caught up', $this->html('<x-ui.notifications />'));
    }

    public function test_new_components_render_without_leaking_blade(): void
    {
        $html = $this->html(<<<'BLADE'
<x-ui.segmented :items="[['label' => 'Day', 'key' => 'day'], ['label' => 'Week', 'active' => true, 'key' => 'w']]" />
<x-ui.stepper :steps="['A', 'B', 'C']" :current="2" />
<x-ui.mini-calendar month="2025-04" selected="2025-04-28" today="2025-04-28" href="/d/{date}" />
<x-ui.filter-panel action="/x" reset-url="/r"><x-ui.field label="Status" name="status"><x-ui.select name="status" :options="['a' => 'A']" /></x-ui.field></x-ui.filter-panel>
<x-ui.list-row title="T" subtitle="S" href="/z" :chevron="true" />
<x-ui.progress :value="67" :percentage="true" label="Done" />
BLADE);
        $this->assertNoBladeLeak($html);
        $this->assertFound($html, '//button[@aria-pressed="true"]');
        $this->assertFound($html, '//li[contains(@class,"is-current")][@aria-current="step"]');
        $this->assertFound($html, '//a[@href="/d/2025-04-28"][@aria-current="date"]');
        $this->assertFound($html, '//form[@method="GET"]//a[@href="/r"]');
        $this->assertStringContainsString('67%', $html);
        $this->assertSame(42, count($this->find($html, '//*[contains(@class,"mini-calendar__day")]')));
    }

    public function test_icon_tile_and_logo(): void
    {
        $this->assertFound($this->html('<x-ui.logo />'), '//img[contains(@src,"wellnest-mark.svg")]');
        $this->assertSame(['WellNest'], $this->find($this->html('<x-ui.logo />'), '//*[contains(@class,"logo__name")]'));
        $this->assertMissing($this->html('<x-ui.logo :mark="true" />'), '//*[contains(@class,"logo__name")]');
        $this->assertFileExists(public_path('images/wellnest-mark.svg'));
    }

    public function test_every_component_view_compiles(): void
    {
        $count = 0;
        foreach (glob(resource_path('views/components/ui/*.blade.php')) as $file) {
            $compiled = $this->app['blade.compiler']->compileString(file_get_contents($file));
            $this->assertIsString($compiled);
            $count++;
        }
        $this->assertGreaterThan(40, $count);
    }
}
