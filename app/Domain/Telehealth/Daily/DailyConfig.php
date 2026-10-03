<?php

namespace App\Domain\Telehealth\Daily;

use App\Domain\Telehealth\Providers\VideoServiceStatus;

/**
 * The Daily.co connection (code configuration: `config/services.php` → `daily`, from the environment), read at
 * call time so a changed value applies on the next request. One resolver for every caller:
 *
 *  - blank values and the usual placeholders (REPLACE_ME, changeme, your-api-key, xxx…) count as UNSET, so a
 *    half-filled .env never makes an outbound call;
 *  - the fake client is honoured only in the local and testing environments — never in production, whatever
 *    DAILY_FAKE says;
 *  - an unknown media region or a non-https API base is ignored rather than sent.
 */
final class DailyConfig
{
    public const DEFAULT_API_BASE = 'https://api.daily.co/v1';

    /** The AWS regions Daily accepts as a room `geo` (Montreal is set up by Daily support, not through `geo`). */
    public const GEO_REGIONS = [
        'af-south-1', 'ap-northeast-2', 'ap-southeast-1', 'ap-southeast-2', 'ap-south-1',
        'eu-central-1', 'eu-west-2', 'sa-east-1', 'us-east-1', 'us-west-2',
    ];

    /** Smallest webhook secret accepted (bytes after base64 decoding); we generate 32. */
    public const MIN_SECRET_BYTES = 16;

    public function apiKey(): ?string
    {
        return self::value(config('services.daily.api_key'));
    }

    /** The decoded HMAC key for webhook signatures, or null when unset or not valid base64 (every webhook is then refused). */
    public function webhookKey(): ?string
    {
        $secret = $this->webhookSecret();
        if ($secret === null) {
            return null;
        }

        $bytes = base64_decode($secret, true);

        return is_string($bytes) && strlen($bytes) >= self::MIN_SECRET_BYTES ? $bytes : null;
    }

    /** The base64 secret as configured (what is registered with Daily as the webhook's `hmac`). */
    public function webhookSecret(): ?string
    {
        return self::value(config('services.daily.webhook_secret'));
    }

    public function geo(): ?string
    {
        $geo = self::value(config('services.daily.geo'));

        return $geo !== null && in_array(strtolower($geo), self::GEO_REGIONS, true) ? strtolower($geo) : null;
    }

    public function apiBase(): string
    {
        $base = self::value(config('services.daily.api_base'));

        return $base !== null && str_starts_with(strtolower($base), 'https://') ? rtrim($base, '/') : self::DEFAULT_API_BASE;
    }

    public function connectTimeout(): float
    {
        return max(0.5, (float) config('services.daily.connect_timeout', 3));
    }

    public function timeout(): float
    {
        return max(1.0, (float) config('services.daily.timeout', 8));
    }

    /** The simulated client (no network): only when asked for AND the app runs locally or under tests. */
    public function fake(): bool
    {
        return (bool) config('services.daily.fake', false) && (app()->isLocal() || app()->runningUnitTests());
    }

    /** The client to talk to: the simulated one when fake() says so, the HTTP one when a key is set. */
    public function client(): DailyApi
    {
        return match (true) {
            $this->fake() => app(FakeDailyClient::class),
            $this->apiKey() !== null => app(DailyClient::class),
            default => throw DailyException::notConfigured(),
        };
    }

    public function status(): VideoServiceStatus
    {
        return match (true) {
            $this->fake() => VideoServiceStatus::Fake,
            $this->apiKey() !== null => VideoServiceStatus::Connected,
            default => VideoServiceStatus::NotConfigured,
        };
    }

    /** A configured value, or null for blank and placeholder values. */
    public static function value(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || preg_match('/^(<.*>|replace[_ -]?me|change[_ -]?me|your[_ -]?(api[_ -]?)?key(?:[_ -]?here)?|x{3,}.*|null|none|todo|tbd)$/i', $value) === 1) {
            return null;
        }

        return $value;
    }
}
