<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrganizationMember> */
class OrganizationMemberFactory extends Factory
{
    protected $model = OrganizationMember::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'role' => $this->faker->randomElement(['owner','admin','member','viewer']),
            'invited_by' => null,
            'invited_at' => $this->faker->optional()->dateTimeBetween('-30 days', 'now'),
            'joined_at' => $this->faker->optional()->dateTimeBetween('-30 days', 'now'),
        ];
    }
}

