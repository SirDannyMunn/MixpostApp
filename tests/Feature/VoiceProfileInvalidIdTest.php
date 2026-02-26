<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureBillingAccess;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VoiceProfileInvalidIdTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsOrgMember(): Organization
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();

        OrganizationMember::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        Sanctum::actingAs($user);
        $this->withoutMiddleware(EnsureBillingAccess::class);

        return $org;
    }

    public function test_batch_attach_posts_rejects_non_uuid_id(): void
    {
        $org = $this->actingAsOrgMember();

        $res = $this->postJson('/api/v1/voice-profiles/undefined/posts/batch', [
            'posts' => [
                [
                    'normalized_content_id' => '63eeb563-8492-498d-a488-2e2d8ee442de',
                    'weight' => 1,
                    'locked' => false,
                ],
            ],
        ], [
            'X-Organization-Id' => $org->id,
        ]);

        $res->assertStatus(400);
        $res->assertJsonFragment([
            'message' => 'Validation failed',
        ]);
        $res->assertJsonPath('errors.id.0', 'The id must be a valid UUID.');
    }
}
