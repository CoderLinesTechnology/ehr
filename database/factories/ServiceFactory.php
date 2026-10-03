<?php

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Requires a tenant context. Attach providers/locations with
 * $service->providers()->attach($membershipId) inside the same context.
 *
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Individual therapy', 'Initial assessment', 'Couples therapy', 'Family session', 'Psychiatric review', 'Follow-up']).' '.fake()->unique()->numberBetween(1, 9999),
            'code' => fake()->optional()->numerify('908##'),
            'duration_minutes' => 50,
            'price_minor' => 30000,
            'currency' => 'GHS',
            'allows_in_person' => true,
            'allows_telehealth' => true,
            'is_bookable_online' => true,
            'billing_behavior' => 'billable',
            'is_active' => true,
        ];
    }

    public function inPersonOnly(): static
    {
        return $this->state(fn () => ['allows_in_person' => true, 'allows_telehealth' => false]);
    }

    public function telehealthOnly(): static
    {
        return $this->state(fn () => ['allows_in_person' => false, 'allows_telehealth' => true]);
    }
}
