<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SocialAccount> */
class SocialAccountFactory extends Factory
{
    protected $model = SocialAccount::class;

    public function definition(): array
    {
        $platform = $this->faker->randomElement(['instagram','tiktok','youtube','twitter','linkedin','facebook','pinterest']);
        return [
            'organization_id' => Organization::factory(),
            'connected_by' => User::factory(),
            'platform' => $platform,
            'platform_user_id' => (string) $this->faker->unique()->numerify('#########'),
            'username' => $this->faker->userName(),
            'display_name' => $this->faker->name(),
            'avatar_url' => $this->faker->optional()->imageUrl(200, 200, 'avatar', true),
            'access_token' => base64_encode($this->faker->uuid()),
            'refresh_token' => $this->faker->optional()->sha256(),
            'token_expires_at' => $this->faker->optional()->dateTimeBetween('+7 days', '+60 days'),
            'is_active' => true,
            'last_sync_at' => $this->faker->optional()->dateTimeBetween('-7 days', 'now'),
            'scopes' => ['basic','posts'],
            'connected_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
        ];
    }
}

