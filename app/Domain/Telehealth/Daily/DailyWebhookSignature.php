<?php

namespace App\Domain\Telehealth\Daily;

/**
 * Daily's webhook signature, checked before anything is parsed or stored:
 * X-Webhook-Signature = base64( HMAC-SHA256( base64_decode(secret), X-Webhook-Timestamp + "." + raw body ) ),
 * compared in constant time. The timestamp must be within ±5 minutes (Daily documents no window; replays inside
 * it are harmless because processing is idempotent). With no usable secret configured every request is refused.
 */
final class DailyWebhookSignature
{
    public const TOLERANCE_SECONDS = 300;

    public function __construct(private readonly DailyConfig $config) {}

    public function valid(string $rawBody, ?string $signature, ?string $timestamp): bool
    {
        $key = $this->config->webhookKey();
        if ($key === null || $signature === null || $timestamp === null || preg_match('/^\d{1,16}$/', $timestamp) !== 1) {
            return false;
        }

        // The docs do not say whether the timestamp is in seconds or milliseconds: accept either, sign it verbatim.
        $seconds = (int) $timestamp;
        if ($seconds > 100_000_000_000) {
            $seconds = intdiv($seconds, 1000);
        }
        if (abs(now()->getTimestamp() - $seconds) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $timestamp.'.'.$rawBody, $key, true));

        return hash_equals($expected, trim($signature));
    }
}
