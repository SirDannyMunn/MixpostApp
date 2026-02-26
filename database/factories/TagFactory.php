<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tag> */
class TagFactory extends Factory
{
    protected $model = Tag::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => ucfirst($this->faker->unique()->word()),
            'color' => $this->faker->hexColor(),
            'created_by' => User::factory(),
        ];
    }
}

