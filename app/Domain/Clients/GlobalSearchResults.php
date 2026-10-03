<?php

namespace App\Domain\Clients;

use App\Models\Client;
use App\Models\OrganizationMembership;

/** What the top-bar search found, section by section, and which sections the searcher may use at all. */
final readonly class GlobalSearchResults
{
    /**
     * @param  SearchHits<Client>  $clients
     * @param  SearchHits<OrganizationMembership>  $staff  each with its user loaded (name, email)
     */
    public function __construct(
        public string $term,
        public bool $canSearchClients,
        public bool $canSearchStaff,
        public SearchHits $clients,
        public SearchHits $staff,
    ) {}

    public function isEmpty(): bool
    {
        return $this->clients->isEmpty() && $this->staff->isEmpty();
    }
}
