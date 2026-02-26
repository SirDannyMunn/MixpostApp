<?php

namespace Database\Factories;

use App\Models\MediaImage;
use App\Models\MediaPack;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MediaImage> */
class MediaImageFactory extends Factory
{
    protected $model = MediaImage::class;

    public function definition(): array
    {
        $filename = uniqid('img_').'.jpg';
        $path = 'media/'.$this->faker->numberBetween(1, 5).'/images/'.$filename;
        return [
            'organization_id' => Organization::factory(),
            'pack_id' => null,
            'uploaded_by' => User::factory(),
            'filename' => $filename,
            'original_filename' => $filename,
            'file_path' => $path,
            'thumbnail_path' => $path,
            'file_size' => $this->faker->numberBetween(10_000, 2_000_000),
            'mime_type' => 'image/jpeg',
            'width' => $this->faker->numberBetween(320, 4000),
            'height' => $this->faker->numberBetween(320, 4000),
            'generation_type' => $this->faker->randomElement(['upload','ai_generated']),
            'ai_prompt' => $this->faker->optional()->sentence(),
        ];
    }
}
