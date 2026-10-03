<?php

namespace Tests\Feature\Settings\Http;

use App\Domain\Settings\SettingsService;
use App\Models\AuditLog;
use App\Models\Organization;
use PHPUnit\Framework\Attributes\Test;

class OrganizationSettingsHttpTest extends SettingsHttpTestCase
{
    private function general(array $overrides = []): array
    {
        return $overrides + [
            'section' => 'general', 'timezone' => 'Africa/Lagos', 'currency' => 'NGN', 'locale' => 'en',
            'description' => 'We help people.',
            'settings' => ['general__date_format' => 'Y-m-d', 'general__time_format' => 'g:i A', 'general__week_starts_on' => '7',
                'branding__primary_color' => '#112233', 'branding__secondary_color' => '#aabbcc'],
        ];
    }

    #[Test]
    public function the_page_shows_the_profile_general_information_branding_and_contact_cards(): void
    {
        $this->actAs($this->admin, fn () => $this->org->forceFill(['tagline' => 'Care for all', 'email' => 'hello@accra.test'])->save());

        $this->asAdmin()->get($this->path('organization'))->assertOk()
            ->assertSee('Organization Settings')->assertSee('Organization Profile')->assertSee('General Information')
            ->assertSee('Branding')->assertSee('Contact Information')->assertSee('Care for all')->assertSee('hello@accra.test')
            ->assertSee('Recommended size: 512 x 512px', false)->assertSee('File type: PNG, JPG', false)
            ->assertSee('(GMT) Africa/Accra')->assertSee('0/500')->assertSee('#2563EB');
    }

    #[Test]
    public function the_general_form_saves_formats_description_and_brand_colours_and_audits_it(): void
    {
        $this->asAdmin()->put($this->path('organization'), $this->general())->assertRedirect($this->path('organization'))
            ->assertSessionHas('success');

        $org = Organization::query()->findOrFail($this->org->id);
        $this->assertSame('Africa/Lagos', $org->timezone);
        $this->assertSame('NGN', $org->currency);
        $this->assertSame('We help people.', $org->description);
        $this->assertSame('Accra Wellness Centre', $org->name, 'fields of other sections keep their value');

        $settings = app(SettingsService::class);
        $this->assertSame('Y-m-d', $settings->organization($org, 'general.date_format'));
        $this->assertSame('#112233', $settings->organization($org, 'branding.primary_color'));
        $this->assertSame('7', $settings->organization($org, 'general.week_starts_on'));
        $this->assertNotNull(AuditLog::query()->where('action', 'organization.profile_updated')->where('organization_id', $org->id)->first());
        $this->assertStringContainsString('keep the timezone and currency', (string) session('success'));
    }

    #[Test]
    public function the_profile_and_contact_sections_change_only_their_own_fields(): void
    {
        $this->asAdmin()->put($this->path('organization'), ['section' => 'profile', 'name' => 'Accra Wellness', 'legal_name' => 'AWC Ltd', 'tagline' => 'Mental Health & Wellness'])->assertRedirect();
        $this->put($this->path('organization'), ['section' => 'contact', 'email' => 'info@accra.test', 'phone' => '+233 24 123 4567', 'website' => 'https://accra.test',
            'address_line1' => '1 High St', 'city' => 'Accra', 'country_code' => 'GH'])->assertRedirect();

        $org = Organization::query()->findOrFail($this->org->id);
        $this->assertSame(['Accra Wellness', 'AWC Ltd', 'Mental Health & Wellness'], [$org->name, $org->legal_name, $org->tagline]);
        $this->assertSame(['info@accra.test', '+233 24 123 4567', 'Accra'], [$org->email, $org->phone, $org->city]);
        $this->assertSame('Africa/Accra', $org->timezone);
    }

    #[Test]
    public function invalid_values_come_back_as_field_errors_and_change_nothing(): void
    {
        $this->asAdmin();
        $this->from($this->path('organization'))->put($this->path('organization'), $this->general(['timezone' => 'Mars/Base', 'description' => str_repeat('x', 501),
            'settings' => ['general__date_format' => 'nope', 'general__time_format' => 'H:i', 'general__week_starts_on' => '1', 'branding__primary_color' => 'blue', 'branding__secondary_color' => '#93C5FD']]))
            ->assertSessionHasErrors(['timezone', 'description', 'settings.general__date_format', 'settings.branding__primary_color']);

        $this->put($this->path('organization'), ['section' => 'contact', 'email' => 'not-an-email', 'website' => 'javascript:alert(1)', 'country_code' => 'GH'])
            ->assertSessionHasErrors(['email', 'website']);
        $this->put($this->path('organization'), ['section' => 'profile', 'name' => ''])->assertSessionHasErrors('name');

        $this->assertSame('Africa/Accra', Organization::query()->findOrFail($this->org->id)->timezone);
    }

    #[Test]
    public function an_unknown_setting_is_refused_so_nothing_is_saved(): void
    {
        $payload = $this->general();
        $payload['settings']['scheduling__min_notice_hours'] = '0';

        $this->asAdmin()->put($this->path('organization'), $payload)->assertSessionHasErrors('settings');
        $this->assertSame('Africa/Accra', Organization::query()->findOrFail($this->org->id)->timezone);
    }

    #[Test]
    public function the_edit_drawer_reopens_with_its_errors(): void
    {
        $this->asAdmin()->from($this->path('organization'))->followingRedirects()
            ->put($this->path('organization'), ['section' => 'profile', 'name' => ''])
            ->assertOk()->assertSee('data-open-on-load', false)->assertSee('Enter the name of your organization.');
    }

    #[Test]
    public function the_user_content_is_escaped(): void
    {
        $this->asAdmin()->put($this->path('organization'), ['section' => 'profile', 'name' => '<script>alert(1)</script>', 'tagline' => '"><img src=x onerror=1>'])->assertRedirect();

        $html = $this->get($this->path('organization'))->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
    }
}
