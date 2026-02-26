<?php

namespace Database\Factories;

use App\Models\SocialAccount;
use App\Models\SocialAnalytics;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SocialAnalytics> */
class SocialAnalyticsFactory extends Factory
{
    protected $model = SocialAnalytics::class;

    public function definition(): array
    {
        return [
            'social_account_id' => SocialAccount::factory(),
            'date' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'followers_count' => $this->faker->numberBetween(100, 200000),
            'following_count' => $this->faker->numberBetween(10, 5000),
            'posts_count' => $this->faker->numberBetween(0, 5000),
            'likes_count' => $this->faker->numberBetween(0, 100000),
            'comments_count' => $this->faker->numberBetween(0, 50000),
            'shares_count' => $this->faker->numberBetween(0, 50000),
            'views_count' => $this->faker->numberBetween(0, 2000000),
            'impressions_count' => $this->faker->numberBetween(0, 3000000),
            'engagement_rate' => $this->faker->randomFloat(2, 0, 15),
            'raw_data' => null,
        ];
    }
}

