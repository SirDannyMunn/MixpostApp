<?php

namespace Database\Factories;

use App\Models\Bookmark;
use App\Models\Folder;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Bookmark> */
class BookmarkFactory extends Factory
{
    protected $model = Bookmark::class;

    public function definition(): array
    {
        $url = $this->faker->url();
        return [
            'organization_id' => Organization::factory(),
            'folder_id' => null,
            'created_by' => User::factory(),
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->optional()->paragraph(),
            'url' => $url,
            'image_url' => $this->faker->optional()->imageUrl(),
            'favicon_url' => $this->faker->optional()->imageUrl(32, 32, 'favicon', true),
            'platform' => $this->faker->randomElement(['instagram','tiktok','youtube','twitter','linkedin','pinterest','other']),
            'platform_metadata' => null,
            'type' => $this->faker->randomElement(['inspiration','reference','competitor','trend']),
            'is_favorite' => $this->faker->boolean(20),
            'is_archived' => false,
        ];
    }
}

