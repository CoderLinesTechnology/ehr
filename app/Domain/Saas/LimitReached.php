<?php

namespace App\Domain\Saas;

use App\Domain\Shared\DomainException;

final class LimitReached extends DomainException
{
    public static function for(string $limitKey, int $limit): self
    {
        $what = collect(FeatureRegistry::definitions())->firstWhere('key', $limitKey)['name'] ?? $limitKey;

        return new self(
            "Your plan allows {$limit} ".mb_strtolower($what).'. Contact your administrator to upgrade or raise the limit.',
            'limit_reached',
        );
    }
}
