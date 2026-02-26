<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureBillingAccess;
use App\Models\Folder;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FolderRenamingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsOrgMember(): array
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

        return [$org, $user];
    }

    public function test_list_folders_returns_system_display_and_effective_name(): void
    {
        [$org, $user] = $this->actingAsOrgMember();

        $folder = Folder::factory()->create([
            'organization_id' => $org->id,
            'created_by' => $user->id,
            'system_name' => 'Laundry Industry Knowledge',
            'display_name' => 'My Laundry Notes',
        ]);

        $res = $this->getJson('/api/v1/folders', [
            'X-Organization-Id' => $org->id,
        ]);

        $res->assertStatus(200);
        $res->assertJsonFragment([
            'id' => $folder->id,
            'system_name' => 'Laundry Industry Knowledge',
            'display_name' => 'My Laundry Notes',
            'effective_name' => 'My Laundry Notes',
            'name' => 'My Laundry Notes',
        ]);
    }

    public function test_patch_updates_only_display_name_and_keeps_system_name_immutable(): void
    {
        [$org, $user] = $this->actingAsOrgMember();

        $folder = Folder::factory()->create([
            'organization_id' => $org->id,
            'created_by' => $user->id,
            'system_name' => 'High-Performing LinkedIn Styles',
            'display_name' => null,
        ]);

        $res = $this->patchJson('/api/v1/folders/' . $folder->id, [
            'display_name' => 'Q1 Campaign Research',
        ], [
            'X-Organization-Id' => $org->id,
        ]);

        $res->assertStatus(200);
        $res->assertJsonFragment([
            'id' => $folder->id,
            'system_name' => 'High-Performing LinkedIn Styles',
            'display_name' => 'Q1 Campaign Research',
            'effective_name' => 'Q1 Campaign Research',
        ]);

        $folder->refresh();
        $this->assertSame('High-Performing LinkedIn Styles', $folder->system_name);
        $this->assertSame('Q1 Campaign Research', $folder->display_name);
    }

    public function test_patch_rejects_system_name_in_payload(): void
    {
        [$org, $user] = $this->actingAsOrgMember();

        $folder = Folder::factory()->create([
            'organization_id' => $org->id,
            'created_by' => $user->id,
            'system_name' => 'System',
        ]);

        $res = $this->patchJson('/api/v1/folders/' . $folder->id, [
            'system_name' => 'Hacked',
        ], [
            'X-Organization-Id' => $org->id,
        ]);

        $res->assertStatus(422);

        $folder->refresh();
        $this->assertSame('System', $folder->system_name);
    }

    public function test_patch_allows_resetting_display_name_with_empty_string_or_null(): void
    {
        [$org, $user] = $this->actingAsOrgMember();

        $folder = Folder::factory()->create([
            'organization_id' => $org->id,
            'created_by' => $user->id,
            'system_name' => 'System Name',
            'display_name' => 'Custom Name',
        ]);

        $res1 = $this->patchJson('/api/v1/folders/' . $folder->id, [
            'display_name' => '',
        ], [
            'X-Organization-Id' => $org->id,
        ]);
        $res1->assertStatus(200);
        $res1->assertJsonFragment([
            'id' => $folder->id,
            'display_name' => null,
            'effective_name' => 'System Name',
        ]);

        $res2 = $this->patchJson('/api/v1/folders/' . $folder->id, [
            'display_name' => null,
        ], [
            'X-Organization-Id' => $org->id,
        ]);
        $res2->assertStatus(200);
        $res2->assertJsonFragment([
            'id' => $folder->id,
            'display_name' => null,
            'effective_name' => 'System Name',
        ]);
    }

    public function test_org_scoping_prevents_cross_org_rename(): void
    {
        [$org, $user] = $this->actingAsOrgMember();
        $otherOrg = Organization::factory()->create();

        $folder = Folder::factory()->create([
            'organization_id' => $otherOrg->id,
            'created_by' => $user->id,
            'system_name' => 'Other Org Folder',
        ]);

        $res = $this->patchJson('/api/v1/folders/' . $folder->id, [
            'display_name' => 'Attempt',
        ], [
            'X-Organization-Id' => $org->id,
        ]);

        $res->assertStatus(404);
    }
}
