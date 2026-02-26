<?php

namespace Database\Factories;

use App\Models\MediaPack;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MediaPack> */
class MediaPackFactory extends Factory
{
    protected $model = MediaPack::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => ucfirst($this->faker->words(2, true)),
            'description' => $this->faker->optional()->sentence(),
            'created_by' => User::factory(),
            'image_count' => 0,
        ];
    }
}

