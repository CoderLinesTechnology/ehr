<?php

namespace App\Domain\Clients;

use App\Domain\Settings\SettingsService;
use App\Domain\Shared\DomainException;
use App\Models\Organization;

/**
 * Which fields an organization insists on for a NEW client
 * (clients.require_date_of_birth, clients.require_contact). Read at call
 * time, so an administrator's change applies on the next request.
 *
 * The form request asks it what to demand (for field-level messages); the
 * create action asks it to refuse (so no other caller can bypass the setting).
 */
final class ClientRequirements
{
    public function __construct(private readonly SettingsService $settings) {}

    public function dateOfBirthRequired(Organization $organization): bool
    {
        return (bool) $this->settings->organization($organization, 'clients.require_date_of_birth');
    }

    public function contactRequired(Organization $organization): bool
    {
        return (bool) $this->settings->organization($organization, 'clients.require_contact');
    }

    /**
     * @param  array<string, mixed>  $data  already trimmed (blank = null)
     *
     * @throws DomainException
     */
    public function assertSatisfied(Organization $organization, array $data): void
    {
        if ($this->dateOfBirthRequired($organization) && blank($data['date_of_birth'] ?? null)) {
            throw new DomainException('Enter the client\'s date of birth.', 'date_of_birth_required', 'date_of_birth');
        }

        if ($this->contactRequired($organization) && blank($data['email'] ?? null) && blank($data['phone'] ?? null)) {
            throw new DomainException('Enter an email address or a phone number so the client can be reached.', 'contact_required', 'email');
        }
    }
}
