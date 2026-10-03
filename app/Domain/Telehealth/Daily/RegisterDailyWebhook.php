<?php

namespace App\Domain\Telehealth\Daily;

use App\Domain\Shared\DomainException;

/**
 * Registers (or updates) the Daily domain's webhook: our receiver URL, OUR base64 secret as `hmac` (so the
 * verification ping Daily sends at once can be checked), the two recording events, and exponential retries (the
 * default circuit breaker disables a webhook silently after three failures). Daily pings the URL synchronously and
 * refuses the registration unless it answers 200 within 8 seconds: deploy the receiver first.
 */
final class RegisterDailyWebhook
{
    public function __construct(private readonly DailyConfig $config) {}

    /** @return array{uuid: string, state: string} */
    public function __invoke(string $url, ?string $uuid = null): array
    {
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new DomainException('The webhook URL must be a public https address.', 'webhook_url');
        }
        if ($uuid !== null && preg_match('/^[A-Za-z0-9-]{1,64}$/', $uuid) !== 1) {
            throw new DomainException('That webhook id is not valid.', 'webhook_uuid');
        }

        $secret = $this->config->webhookSecret();
        if ($secret === null || $this->config->webhookKey() === null) {
            throw new DomainException('Set DAILY_WEBHOOK_SECRET to a base64 secret of at least '.DailyConfig::MIN_SECRET_BYTES.' bytes first.', 'webhook_secret');
        }

        try {
            return $this->config->client()->upsertWebhook($url, $secret, HandleDailyWebhook::EVENTS, $uuid);
        } catch (DailyException $e) {
            throw new DomainException(
                $e->errorType === 'not-configured' ? 'Set DAILY_API_KEY first.' : "Daily refused the webhook ({$e->errorType}). Check that the receiver is deployed and answers at that URL.",
                'webhook_refused',
            );
        }
    }
}
