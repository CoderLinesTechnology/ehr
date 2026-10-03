<?php

namespace Database\Factories;

use App\Domain\Identity\MembershipStatus;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Requires a tenant context. Prefer the addStaff() test helper, which also
 * assigns a role.
 *
 * @extends Factory<OrganizationMembership>
 */
class OrganizationMembershipFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->randomElement(['Clinical Psychologist', 'Counsellor', 'Psychiatrist', 'Social Worker', null]),
            'is_provider' => true,
            'color' => fake()->randomElement(['#5b8def', '#3fb68b', '#e4a33b', '#a77bd8', '#e8697d', '#4fb3c8']),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (OrganizationMembership $membership) {
            if ($membership->user_id === null && $membership->status === null) {
                $membership->user_id = User::factory()->create()->id;
            }
            $membership->forceFill([
                'status' => $membership->status ?? MembershipStatus::Active,
                'joined_at' => $membership->joined_at ?? now(),
            ]);
        });
    }
}
