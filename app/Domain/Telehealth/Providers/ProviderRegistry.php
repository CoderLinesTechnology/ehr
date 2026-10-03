<?php

namespace App\Domain\Telehealth\Providers;

use InvalidArgumentException;

/** Resolves a provider by the key stored on a session. One provider ships today; add a vendor by listing it here. */
final class ProviderRegistry
{
    public const DEFAULT = ExternalLinkProvider::KEY;

    /** @var array<string, class-string<MeetingProvider>> */
    private const PROVIDERS = [ExternalLinkProvider::KEY => ExternalLinkProvider::class];

    public function get(string $key): MeetingProvider
    {
        $class = self::PROVIDERS[$key] ?? throw new InvalidArgumentException("Unknown telehealth provider [{$key}].");

        return app($class);
    }

    public function default(): MeetingProvider
    {
        return $this->get(self::DEFAULT);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::PROVIDERS);
    }
}
