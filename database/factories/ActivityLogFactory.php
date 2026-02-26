<?php

namespace Database\Factories;

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ActivityLog> */
class ActivityLogFactory extends Factory
{
    protected $model = ActivityLog::class;

    public function definition(): array
    {
        $subjectType = $this->faker->optional()->randomElement(['bookmark','template','project','media_image']);
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'action' => $this->faker->randomElement(['created','updated','deleted','login','logout']),
            'subject_type' => $subjectType,
            'subject_id' => $subjectType ? Str::uuid()->toString() : null,
            'description' => $this->faker->sentence(),
            'properties' => null,
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'created_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
        ];
    }
}
