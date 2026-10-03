<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Requires a tenant context (TenantContext::runAs or the inTenant() test helper).
 *
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Osu Clinic', 'East Legon Centre', 'Airport Residential', 'Cantonments Office', 'Labone Suites', 'Spintex Branch']).' '.fake()->unique()->numberBetween(1, 9999),
            'address_line1' => fake()->streetAddress(),
            'city' => 'Accra',
            'region' => 'Greater Accra',
            'country_code' => 'GH',
            'phone' => '+233302'.fake()->numerify('######'),
            'timezone' => 'Africa/Accra',
            'is_active' => true,
        ];
    }
}
