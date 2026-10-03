<?php

namespace App\Console\Commands;

use App\Domain\Shared\DomainException;
use App\Domain\Telehealth\Daily\RegisterDailyWebhook as RegisterWebhook;
use Illuminate\Console\Command;

/**
 * Registers WellNest's receiver as the Daily domain's webhook (docs/integrations/daily.md). Run once per
 * environment after the receiver is deployed; re-run with --uuid to update it (also re-activates a failed one).
 */
final class RegisterDailyWebhook extends Command
{
    protected $signature = 'telehealth:daily-webhook
        {url : Public https URL of the receiver, e.g. https://app.example.com/webhooks/daily}
        {--uuid= : Update this existing Daily webhook instead of creating a new one}';

    protected $description = 'Register (or update) the Daily.co webhook that delivers recording events to WellNest';

    public function handle(RegisterWebhook $register): int
    {
        $uuid = $this->option('uuid');

        try {
            $result = $register((string) $this->argument('url'), is_string($uuid) && $uuid !== '' ? $uuid : null);
        } catch (DomainException $e) {
            $this->error($e->userMessage());

            return self::FAILURE;
        }

        $this->info("Daily webhook {$result['uuid']} is {$result['state']}. Keep the id to update it later (--uuid).");

        return self::SUCCESS;
    }
}
