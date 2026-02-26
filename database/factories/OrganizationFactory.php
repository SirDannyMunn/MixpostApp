<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Organization> */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->company();
        return [
            'name' => $name,
            'slug' => Str::slug($name.'-'.$this->faker->unique()->randomNumber(4)),
            'logo_url' => $this->faker->optional()->imageUrl(200, 200, 'logo', true),
            'subscription_tier' => $this->faker->randomElement(['free','pro','enterprise']),
            'subscription_status' => $this->faker->randomElement(['trial','active','cancelled','expired']),
            'trial_ends_at' => $this->faker->optional()->dateTimeBetween('now', '+30 days'),
            'subscription_ends_at' => $this->faker->optional()->dateTimeBetween('+30 days', '+180 days'),
        ];
    }
}

