<?php

namespace Database\Factories;

use App\Models\ContentPlan;
use App\Models\Organization;
use App\Models\User;
use App\Models\VoiceProfile;
use App\Models\AiCanvasConversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContentPlan> */
class ContentPlanFactory extends Factory
{
    protected $model = ContentPlan::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'conversation_id' => null,
            'plan_type' => 'build_in_public',
            'duration_days' => $this->faker->randomElement([7, 14]),
            'platform' => 'twitter',
            'goal' => $this->faker->sentence(),
            'audience' => $this->faker->sentence(),
            'voice_profile_id' => null,
            'status' => 'draft',
            'continuity_state' => null,
        ];
    }

    public function withVoiceProfile(): static
    {
        return $this->state(fn (array $attributes) => [
            'voice_profile_id' => VoiceProfile::factory(),
        ]);
    }

    public function withConversation(): static
    {
        return $this->state(fn (array $attributes) => [
            'conversation_id' => AiCanvasConversation::factory(),
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'confirmed',
        ]);
    }

    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'ready',
        ]);
    }
}
