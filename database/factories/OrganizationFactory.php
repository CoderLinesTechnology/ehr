<?php

namespace Database\Factories;

use App\Domain\Platform\OrganizationStatus;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A bare organization row (no roles, settings or subscription). Tests that
 * need a working tenant should use the createOrganization() helper in
 * Tests\Concerns\InteractsWithTenancy, which goes through CreateOrganization.
 *
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'country_code' => 'GH',
            'timezone' => 'Africa/Accra',
            'currency' => 'GHS',
            'locale' => 'en',
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Organization $organization) {
            $organization->forceFill([
                'slug' => $organization->slug ?? Str::slug($organization->name).'-'.Str::lower(Str::random(5)),
                'status' => $organization->status ?? OrganizationStatus::Active,
            ]);
        });
    }
}
