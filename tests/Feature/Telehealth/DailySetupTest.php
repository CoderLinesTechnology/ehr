<?php

namespace Tests\Feature\Telehealth;

use App\Domain\Telehealth\Daily\DailyConfig;
use App\Domain\Telehealth\Providers\ProviderRegistry;
use App\Domain\Telehealth\Providers\VideoServiceStatus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

/** Configuration resolution (placeholders, fake only off production) and the move from meeting links to Daily. */
class DailySetupTest extends TelehealthTestCase
{
    #[Test]
    public function the_fake_client_is_never_honoured_in_production(): void
    {
        $config = app(DailyConfig::class);
        $this->assertSame(VideoServiceStatus::Fake, $config->status());

        $environment = $this->app['env'];
        try {
            $this->app['env'] = 'production';
            $this->assertFalse($config->fake());
            $this->assertSame(VideoServiceStatus::NotConfigured, $config->status(), 'DAILY_FAKE=true in production means: not set up');

            config(['services.daily.api_key' => self::API_KEY]);
            $this->assertSame(VideoServiceStatus::Connected, $config->status());
        } finally {
            $this->app['env'] = $environment;
        }
    }

    #[Test]
    public function secrets_regions_and_the_api_base_are_validated_before_use(): void
    {
        $config = app(DailyConfig::class);

        config(['services.daily.webhook_secret' => self::WEBHOOK_SECRET]);
        $this->assertSame(32, strlen((string) $config->webhookKey()));
        foreach (['', 'changeme', 'not base64!', base64_encode('fifteen bytes!!')] as $bad) {
            config(['services.daily.webhook_secret' => $bad]);
            $this->assertNull($config->webhookKey(), "[{$bad}] was accepted as a webhook secret");
        }

        config(['services.daily.geo' => 'AF-SOUTH-1']);
        $this->assertSame('af-south-1', $config->geo());
        config(['services.daily.geo' => 'eu-west-9']);
        $this->assertNull($config->geo());

        config(['services.daily.api_base' => 'http://api.daily.co/v1']);
        $this->assertSame(DailyConfig::DEFAULT_API_BASE, $config->apiBase(), 'never send the key over plain http');
        config(['services.daily.api_base' => 'https://api.daily.co/v1/']);
        $this->assertSame('https://api.daily.co/v1', $config->apiBase());
    }

    #[Test]
    public function daily_is_the_only_provider_and_new_sessions_use_it(): void
    {
        $registry = app(ProviderRegistry::class);

        $this->assertSame(['daily'], $registry->keys());
        $this->assertSame('Daily', $registry->default()->label());
        $this->assertTrue($registry->default()->capabilities()->waitingRoom);
        $this->assertSame('daily', $this->sessionAt('2026-10-06 10:00:00', room: false)->provider_key);
    }

    #[Test]
    public function the_data_step_moves_link_sessions_to_daily_and_removes_the_link_settings(): void
    {
        $session = $this->sessionAt('2026-10-06 10:00:00', room: false);
        DB::table('telehealth_sessions')->where('id', $session->id)
            ->update(['provider_key' => 'external_link', 'join_url' => Crypt::encryptString('https://zoom.us/j/1?pwd=Old')]);
        foreach (['telehealth.default_link_secret' => '"x"', 'telehealth.allowed_hosts' => '"zoom.us"', 'telehealth.join_early_minutes' => '30'] as $key => $value) {
            DB::table('organization_settings')->insert(['organization_id' => $this->organization->id, 'key' => $key, 'value' => $value, 'updated_at' => now()]);
        }

        $migration = require database_path('migrations/2026_10_06_000110_move_telehealth_sessions_to_daily.php');
        $migration->up();
        $migration->up();   // idempotent

        $row = DB::table('telehealth_sessions')->where('id', $session->id)->first();
        $this->assertSame('daily', $row->provider_key);
        $this->assertNull($row->join_url);
        $this->assertSame(['telehealth.join_early_minutes'], DB::table('organization_settings')->where('organization_id', $this->organization->id)
            ->where('key', 'like', 'telehealth.%')->pluck('key')->all(), 'only the link settings are removed');
        $this->assertSame(0, DB::table('telehealth_sessions')->where('provider_key', 'external_link')->count());
        $this->assertTrue(Str::isUuid($row->id));
    }
}
