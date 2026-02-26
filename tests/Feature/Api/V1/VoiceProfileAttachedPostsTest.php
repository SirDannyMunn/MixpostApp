<?php

namespace Tests\Feature\Api\V1;

use App\Models\VoiceProfile;
use App\Models\VoiceProfilePost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LaundryOS\SocialWatcher\Models\NormalizedContent;
use Tests\TestCase;

class VoiceProfileAttachedPostsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate');
    }

    public function test_list_attached_posts_returns_empty_for_new_profile(): void
    {
        $user = User::factory()->create();
        $orgId = Str::uuid()->toString();

        $profile = VoiceProfile::create([
            'id' => Str::uuid()->toString(),
            'organization_id' => $orgId,
            'user_id' => $user->id,
            'name' => 'Test Profile',
        ]);

        $response = $this->actingAs($user)
            ->withHeaders(['X-Organization-ID' => $orgId])
            ->getJson("/api/v1/voice-profiles/{$profile->id}/posts");

        $response->assertOk()
            ->assertJson([
                'data' => [],
                'meta' => [
                    'total' => 0,
                ],
            ]);
    }

    public function test_list_attached_posts_returns_minimal_data_by_default(): void
    {
        $user = User::factory()->create();
        $orgId = Str::uuid()->toString();

        $profile = VoiceProfile::create([
            'id' => Str::uuid()->toString(),
            'organization_id' => $orgId,
            'user_id' => $user->id,
            'name' => 'Test Profile',
        ]);

        // Create normalized content
        $content = NormalizedContent::create([
            'id' => Str::uuid()->toString(),
            'source_id' => 1,
            'platform' => 'twitter',
            'text' => 'Test post content',
            'engagement_score' => 50,
            'published_at' => now(),
        ]);

        // Attach to profile
        VoiceProfilePost::create([
            'voice_profile_id' => $profile->id,
            'normalized_content_id' => $content->id,
            'weight' => 1.5,
            'locked' => true,
        ]);

        $response = $this->actingAs($user)
            ->withHeaders(['X-Organization-ID' => $orgId])
            ->getJson("/api/v1/voice-profiles/{$profile->id}/posts");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'normalized_content_id',
                        'weight',
                        'locked',
                        'attached_at',
                    ],
                ],
                'meta' => [
                    'current_page',
                    'per_page',
                    'total',
                    'last_page',
                ],
            ])
            ->assertJsonPath('data.0.normalized_content_id', $content->id)
            ->assertJsonPath('data.0.weight', 1.5)
            ->assertJsonPath('data.0.locked', true)
            ->assertJsonMissing(['normalized_content']);
    }

    public function test_list_attached_posts_includes_metadata_when_requested(): void
    {
        $user = User::factory()->create();
        $orgId = Str::uuid()->toString();

        $profile = VoiceProfile::create([
            'id' => Str::uuid()->toString(),
            'organization_id' => $orgId,
            'user_id' => $user->id,
            'name' => 'Test Profile',
        ]);

        $content = NormalizedContent::create([
            'id' => Str::uuid()->toString(),
            'source_id' => 1,
            'platform' => 'twitter',
            'text' => 'Test post content',
            'author_name' => 'John Doe',
            'engagement_score' => 50,
            'published_at' => now(),
        ]);

        VoiceProfilePost::create([
            'voice_profile_id' => $profile->id,
            'normalized_content_id' => $content->id,
        ]);

        $response = $this->actingAs($user)
            ->withHeaders(['X-Organization-ID' => $orgId])
            ->getJson("/api/v1/voice-profiles/{$profile->id}/posts?include_metadata=true");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'normalized_content_id',
                        'weight',
                        'locked',
                        'attached_at',
                        'normalized_content' => [
                            'id',
                            'platform',
                            'content_text',
                            'author_name',
                            'engagement_score',
                        ],
                    ],
                ],
            ])
            ->assertJsonPath('data.0.normalized_content.author_name', 'John Doe');
    }

    public function test_list_attached_posts_returns_404_for_invalid_profile(): void
    {
        $user = User::factory()->create();
        $orgId = Str::uuid()->toString();
        $fakeId = Str::uuid()->toString();

        $response = $this->actingAs($user)
            ->withHeaders(['X-Organization-ID' => $orgId])
            ->getJson("/api/v1/voice-profiles/{$fakeId}/posts");

        $response->assertNotFound()
            ->assertJson([
                'error' => 'Not Found',
                'message' => 'Voice profile not found',
                'status' => 404,
            ]);
    }

    public function test_list_attached_posts_respects_pagination(): void
    {
        $user = User::factory()->create();
        $orgId = Str::uuid()->toString();

        $profile = VoiceProfile::create([
            'id' => Str::uuid()->toString(),
            'organization_id' => $orgId,
            'user_id' => $user->id,
            'name' => 'Test Profile',
        ]);

        // Create 25 posts
        for ($i = 0; $i < 25; $i++) {
            $content = NormalizedContent::create([
                'id' => Str::uuid()->toString(),
                'source_id' => 1,
                'platform' => 'twitter',
                'text' => "Test post {$i}",
                'engagement_score' => 50,
                'published_at' => now()->subMinutes($i),
            ]);

            VoiceProfilePost::create([
                'voice_profile_id' => $profile->id,
                'normalized_content_id' => $content->id,
            ]);
        }

        $response = $this->actingAs($user)
            ->withHeaders(['X-Organization-ID' => $orgId])
            ->getJson("/api/v1/voice-profiles/{$profile->id}/posts?per_page=10&page=2");

        $response->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonCount(10, 'data');
    }

    public function test_list_attached_posts_accepts_string_boolean_parameters(): void
    {
        $user = User::factory()->create();
        $orgId = Str::uuid()->toString();

        $profile = VoiceProfile::create([
            'id' => Str::uuid()->toString(),
            'organization_id' => $orgId,
            'user_id' => $user->id,
            'name' => 'Test Profile',
        ]);

        $content = NormalizedContent::create([
            'id' => Str::uuid()->toString(),
            'source_id' => 1,
            'platform' => 'twitter',
            'text' => 'Test post',
            'engagement_score' => 50,
            'published_at' => now(),
        ]);

        VoiceProfilePost::create([
            'voice_profile_id' => $profile->id,
            'normalized_content_id' => $content->id,
        ]);

        // Test with "false" string
        $response = $this->actingAs($user)
            ->withHeaders(['X-Organization-ID' => $orgId])
            ->getJson("/api/v1/voice-profiles/{$profile->id}/posts?include_metadata=false");

        $response->assertOk()
            ->assertJsonMissing(['normalized_content']);

        // Test with "true" string
        $response = $this->actingAs($user)
            ->withHeaders(['X-Organization-ID' => $orgId])
            ->getJson("/api/v1/voice-profiles/{$profile->id}/posts?include_metadata=true");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['normalized_content'],
                ],
            ]);

        // Test with numeric "0"
        $response = $this->actingAs($user)
            ->withHeaders(['X-Organization-ID' => $orgId])
            ->getJson("/api/v1/voice-profiles/{$profile->id}/posts?include_metadata=0");

        $response->assertOk()
            ->assertJsonMissing(['normalized_content']);

        // Test with numeric "1"
        $response = $this->actingAs($user)
            ->withHeaders(['X-Organization-ID' => $orgId])
            ->getJson("/api/v1/voice-profiles/{$profile->id}/posts?include_metadata=1");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['normalized_content'],
                ],
            ]);
    }
}
