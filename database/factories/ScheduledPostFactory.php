<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\ScheduledPost;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScheduledPost> */
class ScheduledPostFactory extends Factory
{
    protected $model = ScheduledPost::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'project_id' => null,
            'created_by' => User::factory(),
            'caption' => $this->faker->sentence(10),
            'media_urls' => [$this->faker->imageUrl(1024, 1024)],
            'scheduled_for' => $this->faker->dateTimeBetween('+1 day', '+14 days'),
            'timezone' => 'UTC',
            'status' => 'scheduled',
            'published_at' => null,
            'error_message' => null,
        ];
    }
}

