<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Template;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Template> */
class TemplateFactory extends Factory
{
    protected $model = Template::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'folder_id' => null,
            'created_by' => User::factory(),
            'name' => ucfirst($this->faker->words(3, true)),
            'description' => $this->faker->optional()->sentence(),
            'thumbnail_url' => $this->faker->optional()->imageUrl(),
            'template_type' => $this->faker->randomElement(['slideshow','post','story','reel','custom']),
            'template_data' => [
                'elements' => [
                    ['type' => 'text', 'content' => $this->faker->sentence()],
                    ['type' => 'image', 'src' => $this->faker->imageUrl(800, 600, null, true)],
                ],
            ],
            'category' => $this->faker->optional()->randomElement(['promo','announcement','quote','tips']),
            'is_public' => $this->faker->boolean(10),
            'usage_count' => $this->faker->numberBetween(0, 1000),
        ];
    }
}

