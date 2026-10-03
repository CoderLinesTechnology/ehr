<?php

namespace App\Domain\Platform\Queries;

use App\Domain\Platform\OrganizationStatus;
use App\Domain\Saas\SubscriptionStatus;
use Carbon\CarbonInterface;

/**
 * One row of the platform's organization list. Deliberately small: what the
 * list shows and nothing else, so a screen built on it cannot reach into the
 * organization's data by accident.
 */
final readonly class OrganizationListItem
{
    public function __construct(
        /** The organization's address and route key. */
        public string $slug,
        public string $name,
        public ?string $email,
        public OrganizationStatus $status,
        public ?string $planKey,
        public ?string $planName,
        public ?SubscriptionStatus $subscriptionStatus,
        /** Active plus invited staff memberships. */
        public int $staffCount,
        public CarbonInterface $createdAt,
    ) {}
}
