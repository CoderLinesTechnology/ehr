<?php

namespace Tests\Feature\Platform;

use App\Domain\Platform\UpdatePlatformSettings;
use App\Domain\Saas\FeatureRegistry;
use App\Domain\Settings\SettingDefinition;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\DomainException;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;

class PlatformSettingsTest extends PlatformTestCase
{
    protected function tearDown(): void
    {
        // Undo any definition a test injected: the registry is process-wide.
        (new ReflectionProperty(SettingsRegistry::class, 'definitions'))->setValue(null, null);

        parent::tearDown();
    }

    private function update(array $values, string $reason = 'Quarterly review'): array
    {
        return app(UpdatePlatformSettings::class)($values, $reason, auth()->user());
    }

    /** Register a secret platform setting, as the registry would once an integration declares one. */
    private function declareSecret(): string
    {
        $key = 'integrations.test_secret';
        $registry = new ReflectionProperty(SettingsRegistry::class, 'definitions');
        $definitions = SettingsRegistry::all();
        $definitions[$key] = new SettingDefinition($key, 'platform', SettingDefinition::TYPE_SECRET, null, 'general', 'Test secret', nullable: true);
        $registry->setValue(null, $definitions);

        return $key;
    }

    #[Test]
    public function changed_settings_are_saved_audited_per_key_and_summarised_with_the_reason(): void
    {
        $admin = $this->signInAsPlatform();

        $changed = $this->update([
            'platform.name' => 'Careline',
            'platform.support_email' => 'help@careline.example',
            'platform.announcement_level' => 'warning',
            'registration.mode' => 'approval',
        ], reason: 'Rebrand and moderate sign-ups');

        $this->assertEqualsCanonicalizing(['platform.name', 'platform.support_email', 'platform.announcement_level', 'registration.mode'], $changed);

        $settings = app(SettingsService::class);
        $settings->flush();
        $this->assertSame('Careline', $settings->platform('platform.name'));
        $this->assertSame('help@careline.example', $settings->platform('platform.support_email'));
        $this->assertSame('approval', $settings->platform('registration.mode'));

        // SettingsService audits every changed key...
        $perKey = AuditLog::query()->where('action', 'platform.setting_changed')->get();
        $this->assertCount(4, $perKey);
        $name = $perKey->first(fn (AuditLog $row) => ($row->metadata['key'] ?? null) === 'platform.name');
        $this->assertSame('platform', $name->context);
        $this->assertSame(SettingsRegistry::get('platform.name')->default, $name->before['platform.name']);
        $this->assertSame('Careline', $name->after['platform.name']);
        $this->assertSame($admin->id, $name->actor_user_id);

        // ...and the console adds one entry with the reason and the keys, never the values.
        $summary = $this->audit('platform.settings_updated');
        $this->assertSame('platform', $summary->context);
        $this->assertSame($admin->id, $summary->actor_user_id);
        $this->assertSame('Rebrand and moderate sign-ups', $summary->metadata['reason']);
        $this->assertEqualsCanonicalizing($changed, $summary->after['changed']);
        $this->assertStringNotContainsString('Careline', json_encode($summary->toArray()));
    }

    #[Test]
    public function a_change_needs_a_reason(): void
    {
        $this->signInAsPlatform();

        foreach (['', '   ', str_repeat('r', 501)] as $reason) {
            try {
                $this->update(['platform.name' => 'Nope'], $reason);
                $this->fail('An unusable reason must be refused.');
            } catch (DomainException $e) {
                $this->assertContains($e->errorCode(), ['reason_required', 'reason_too_long']);
            }
        }

        $this->assertSame(0, PlatformSetting::query()->count());
    }

    #[Test]
    public function an_invalid_value_refuses_the_whole_save_and_nothing_is_written(): void
    {
        $this->signInAsPlatform();

        try {
            $this->update([
                'platform.name' => 'Valid Name',
                'registration.mode' => 'chaos',        // not one of open|approval|closed
                'platform.default_currency' => 'GHSX', // must be three letters
            ]);
            $this->fail('Invalid settings must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('registration__mode', $e->errors());
            $this->assertArrayHasKey('platform__default_currency', $e->errors());
        }

        $this->assertSame(0, PlatformSetting::query()->count(), 'The valid value was not saved either.');
        $this->assertSame(0, $this->auditCount('platform.setting_changed'));
        $this->assertSame(0, $this->auditCount('platform.settings_updated'));
    }

    #[Test]
    public function a_setting_the_registry_does_not_declare_cannot_be_written(): void
    {
        $this->signInAsPlatform();

        $this->expectException(\InvalidArgumentException::class);
        $this->update(['platform.backdoor' => 'yes']);
    }

    #[Test]
    public function an_organization_setting_cannot_be_written_through_the_platform_form(): void
    {
        $this->signInAsPlatform();

        $this->expectException(\InvalidArgumentException::class);
        $this->update(['scheduling.allow_overbooking' => false]);
    }

    #[Test]
    public function saving_what_is_already_stored_changes_nothing_and_records_no_summary(): void
    {
        $this->signInAsPlatform();
        $this->update(['platform.name' => 'Careline']);
        $before = $this->auditCount('platform.settings_updated');
        $perKeyBefore = $this->auditCount('platform.setting_changed');

        $changed = $this->update(['platform.name' => 'Careline', 'registration.mode' => 'open']);

        $this->assertSame([], $changed, 'Careline is stored already, and "open" is the default.');
        $this->assertSame($before, $this->auditCount('platform.settings_updated'));
        $this->assertSame($perKeyBefore, $this->auditCount('platform.setting_changed'));
    }

    #[Test]
    public function a_list_setting_stores_the_checked_options_and_an_empty_list_clears_it(): void
    {
        $this->signInAsPlatform();
        $settings = app(SettingsService::class);

        $changed = $this->update(['platform.disabled_features' => [FeatureRegistry::TELEHEALTH, FeatureRegistry::AI, FeatureRegistry::AI]]);
        $this->assertSame(['platform.disabled_features'], $changed);
        $this->assertEqualsCanonicalizing([FeatureRegistry::TELEHEALTH, FeatureRegistry::AI], $settings->platform('platform.disabled_features'));

        // The same set in another order is not a change.
        $this->assertSame([], $this->update(['platform.disabled_features' => [FeatureRegistry::AI, FeatureRegistry::TELEHEALTH]]));

        // An option that is not a module is refused.
        try {
            $this->update(['platform.disabled_features' => ['calendar_of_doom']]);
            $this->fail('An unknown module must be refused.');
        } catch (ValidationException) {
            $this->assertEqualsCanonicalizing([FeatureRegistry::TELEHEALTH, FeatureRegistry::AI], $settings->platform('platform.disabled_features'));
        }

        // Nothing checked means an empty list.
        $this->assertSame(['platform.disabled_features'], $this->update(['platform.disabled_features' => []]));
        $this->assertSame([], $settings->platform('platform.disabled_features'));
    }

    #[Test]
    public function a_secret_is_write_only_encrypted_at_rest_and_never_reaches_the_audit_log(): void
    {
        $this->signInAsPlatform();
        $key = $this->declareSecret();
        $settings = app(SettingsService::class);
        $this->assertFalse($settings->platformSecretIsSet($key));

        $changed = $this->update([$key => 'sk_live_super_secret_value']);

        $this->assertSame([$key], $changed);
        $this->assertTrue($settings->platformSecretIsSet($key), 'The page can say "Set"...');

        $stored = DB::table('platform_settings')->where('key', $key)->value('value');
        $this->assertStringNotContainsString('sk_live_super_secret_value', $stored, '...but the database holds ciphertext only.');
        $this->assertSame('sk_live_super_secret_value', $settings->platform($key), 'The application can still read it.');

        $everythingAudited = json_encode(AuditLog::query()->get()->toArray());
        $this->assertStringNotContainsString('sk_live_super_secret_value', $everythingAudited);
        $perKey = AuditLog::query()->where('action', 'platform.setting_changed')->get()->first(fn ($row) => ($row->metadata['key'] ?? null) === $key);
        $this->assertSame('[redacted]', $perKey->after[$key]);
        $this->assertSame('[redacted]', $perKey->before[$key]);
    }

    #[Test]
    public function leaving_a_secret_empty_keeps_the_stored_value_and_is_not_a_change(): void
    {
        $this->signInAsPlatform();
        $key = $this->declareSecret();
        $settings = app(SettingsService::class);
        $this->update([$key => 'first-value']);
        $before = DB::table('platform_settings')->where('key', $key)->value('value');

        foreach ([null, '', '   '] as $empty) {
            $this->assertSame([], $this->update([$key => $empty]), 'An empty secret input means "keep what is stored".');
        }

        $this->assertSame($before, DB::table('platform_settings')->where('key', $key)->value('value'));
        $this->assertSame('first-value', $settings->platform($key));
        $this->assertSame(1, AuditLog::query()->where('action', 'platform.setting_changed')->get()->filter(fn ($row) => ($row->metadata['key'] ?? null) === $key)->count());

        // Typing a new value replaces it.
        $this->assertSame([$key], $this->update([$key => 'second-value']));
        $this->assertSame('second-value', $settings->platform($key));
    }
}
