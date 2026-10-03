<?php

namespace Database\Factories;

use App\Domain\Clients\ClientStatus;
use App\Domain\Platform\OrganizationCounters;
use App\Domain\Shared\RecordEnvironment;
use App\Domain\Tenancy\TenantContext;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Requires a tenant context. Production code creates clients through the
 * Clients domain action; this factory exists for tests and seeding only.
 *
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'date_of_birth' => fake()->dateTimeBetween('-70 years', '-18 years')->format('Y-m-d'),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+23324'.fake()->numerify('#######'),
            'country_code' => 'GH',
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Client $client) {
            $organizationId = $client->organization_id ?? app(TenantContext::class)->id();
            $client->forceFill([
                'record_environment' => $client->record_environment ?? RecordEnvironment::Live,
                'status' => $client->status ?? ClientStatus::Active,
                'client_number' => $client->client_number
                    ?? app(OrganizationCounters::class)->next($organizationId, OrganizationCounters::CLIENT),
            ]);
        });
    }

    public function demo(): static
    {
        return $this->afterMaking(fn (Client $client) => $client->forceFill(['record_environment' => RecordEnvironment::Demo]));
    }

    public function status(ClientStatus $status): static
    {
        return $this->afterMaking(fn (Client $client) => $client->forceFill(['status' => $status]));
    }
}
