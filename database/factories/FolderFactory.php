<?php

namespace Database\Factories;

use App\Models\Folder;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Folder> */
class FolderFactory extends Factory
{
    protected $model = Folder::class;

    public function configure(): static
    {
        return $this->afterMaking(function (Folder $folder) {
            // system_name is guarded, so ensure it's set directly on the model
            // before the factory persists it.
            $systemName = trim((string) ($folder->system_name ?? ''));
            if ($systemName === '') {
                $folder->system_name = ucfirst(fake()->unique()->word());
            }

            if (!$folder->system_named_at) {
                $folder->system_named_at = now();
            }
        });
    }

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'parent_id' => null,
            'system_name' => ucfirst($this->faker->unique()->word()),
            'display_name' => null,
            'color' => $this->faker->optional()->hexColor(),
            'icon' => $this->faker->optional()->randomElement(['star','folder','bookmark','tag']),
            'position' => $this->faker->numberBetween(0, 1000),
            'created_by' => User::factory(),
        ];
    }
}

