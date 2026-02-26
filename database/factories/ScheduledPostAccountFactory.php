<?php

namespace Database\Factories;

use App\Models\ScheduledPost;
use App\Models\ScheduledPostAccount;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScheduledPostAccount> */
class ScheduledPostAccountFactory extends Factory
{
    protected $model = ScheduledPostAccount::class;

    public function definition(): array
    {
        return [
            'scheduled_post_id' => ScheduledPost::factory(),
            'social_account_id' => SocialAccount::factory(),
            'platform_config' => ['caption_suffix' => '#demo'],
            'status' => 'pending',
            'platform_post_id' => null,
            'published_at' => null,
            'error_message' => null,
        ];
    }
}

