<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'template_id' => null,
            'created_by' => User::factory(),
            'name' => ucfirst($this->faker->words(3, true)),
            'description' => $this->faker->optional()->sentence(),
            'status' => $this->faker->randomElement(['draft','in_progress','completed','archived']),
            'project_data' => ['slides' => [['text' => $this->faker->sentence()]]],
            'rendered_url' => $this->faker->optional()->url(),
            'rendered_at' => $this->faker->optional()->dateTimeBetween('-10 days', 'now'),
        ];
    }
}

