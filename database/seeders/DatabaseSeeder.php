<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\{User, Organization, OrganizationMember, Folder, Tag, Bookmark, Template, MediaPack, MediaImage, Project, SocialAccount, ScheduledPost, ScheduledPostAccount, SocialAnalytics, ActivityLog};
use Illuminate\Support\Arr;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(AdminUserSeeder::class);
        $users = User::factory()->count(5)->create();

        for ($i = 0; $i < 2; $i++) {
            $org = Organization::factory()->create();

            OrganizationMember::create([
                'organization_id' => $org->id,
                'user_id' => $users[0]->id,
                'role' => 'owner',
                'joined_at' => now(),
            ]);
            OrganizationMember::create([
                'organization_id' => $org->id,
                'user_id' => $users[1]->id,
                'role' => 'admin',
                'joined_at' => now(),
            ]);
            OrganizationMember::create([
                'organization_id' => $org->id,
                'user_id' => $users[2]->id,
                'role' => 'member',
                'joined_at' => now(),
            ]);
            OrganizationMember::create([
                'organization_id' => $org->id,
                'user_id' => $users[3]->id,
                'role' => 'viewer',
                'joined_at' => now(),
            ]);

            $folders = Folder::factory()->count(3)->create([
                'organization_id' => $org->id,
                'created_by' => $users[0]->id,
            ]);
            $childFolder = Folder::factory()->create([
                'organization_id' => $org->id,
                'parent_id' => $folders[0]->id,
                'created_by' => $users[1]->id,
            ]);

            $tags = Tag::factory()->count(8)->create([
                'organization_id' => $org->id,
                'created_by' => $users[1]->id,
            ]);

            $bookmarks = Bookmark::factory()->count(20)->create([
                'organization_id' => $org->id,
                'created_by' => $users[2]->id,
                'folder_id' => Arr::random([$folders[0]->id, $folders[1]->id, $childFolder->id, null]),
            ]);
            foreach ($bookmarks as $b) {
                $b->tags()->sync($tags->random(rand(0, 3))->pluck('id'));
            }

            $templates = TemplatesSeeder::seedForOrganization(
                $org,
                $users[1],
                [$folders[2]->id, $childFolder->id]
            );
            foreach ($templates as $t) {
                $t->tags()->sync($tags->random(rand(0, 3))->pluck('id'));
            }

            $packs = MediaPack::factory()->count(3)->create([
                'organization_id' => $org->id,
                'created_by' => $users[2]->id,
            ]);
            foreach ($packs as $pack) {
                $images = MediaImage::factory()->count(4)->create([
                    'organization_id' => $org->id,
                    'uploaded_by' => $users[2]->id,
                    'pack_id' => $pack->id,
                ]);
                $pack->update(['image_count' => $images->count()]);
            }

            $projects = Project::factory()->count(4)->create([
                'organization_id' => $org->id,
                'created_by' => $users[2]->id,
                'template_id' => $templates->random()->id,
            ]);

            $accounts = SocialAccount::factory()->count(3)->create([
                'organization_id' => $org->id,
                'connected_by' => $users[1]->id,
            ]);

            $scheduled = ScheduledPost::factory()->count(10)->create([
                'organization_id' => $org->id,
                'created_by' => $users[2]->id,
                'project_id' => $projects->random()->id,
            ]);
            foreach ($scheduled as $post) {
                $attach = $accounts->random(rand(1, min(2, $accounts->count())));
                foreach ($attach as $acc) {
                    ScheduledPostAccount::create([
                        'scheduled_post_id' => $post->id,
                        'social_account_id' => $acc->id,
                        'status' => 'pending',
                    ]);
                }
            }

            foreach ($accounts as $acc) {
                for ($d = 0; $d < 14; $d++) {
                    SocialAnalytics::factory()->create([
                        'social_account_id' => $acc->id,
                        'date' => now()->subDays($d)->toDateString(),
                    ]);
                }
            }

            ActivityLog::factory()->count(30)->create([
                'organization_id' => $org->id,
                'user_id' => $users->random()->id,
            ]);
        }

        // Top up each organization to ~100 bookmarks with realistic platform data
        $this->call(BookmarksSeeder::class);
        // Seed knowledge lab data
        $this->call(KnowledgeLabSeeder::class);
        // Load current business_facts dump if present
        $this->call(BusinessFactsDumpSeeder::class);
        
        // Seed social watcher sources from apify config
        $this->call(SwSourceSeeder::class);
        
    }
}
