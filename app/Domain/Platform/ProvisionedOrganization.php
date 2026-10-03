<?php

namespace App\Domain\Platform;

final readonly class ProvisionedOrganization
{
    public function __construct(
        public CreatedOrganization $created,
        /** False when the owner's invitation email could not be handed to the mail system. */
        public bool $invitationSent,
    ) {}
}
