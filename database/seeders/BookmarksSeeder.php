<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use App\Models\{Organization, OrganizationMember, User, Folder, Tag, Bookmark};

class BookmarksSeeder extends Seeder
{
    public function run(): void
    {
        $platforms = [
            'instagram', 'tiktok', 'youtube', 'twitter', 'linkedin', 'pinterest', 'other'
        ];

        $tagCatalog = [
            ['name' => 'Design', 'color' => '#0ea5e9'],
            ['name' => 'Inspiration', 'color' => '#22c55e'],
            ['name' => 'Competitor', 'color' => '#ef4444'],
            ['name' => 'How-to', 'color' => '#a855f7'],
            ['name' => 'Copy', 'color' => '#06b6d4'],
            ['name' => 'Trend', 'color' => '#f59e0b'],
            ['name' => 'Video', 'color' => '#eab308'],
            ['name' => 'Carousel', 'color' => '#14b8a6'],
            ['name' => 'Case Study', 'color' => '#ef4444'],
            ['name' => 'Brand', 'color' => '#6366f1'],
            ['name' => 'UGC', 'color' => '#84cc16'],
            ['name' => 'Ad', 'color' => '#fb7185'],
        ];

        foreach (Organization::all() as $org) {
            // Ensure we have members to attribute as creators
            $memberIds = $org->memberships()->pluck('user_id');
            if ($memberIds->isEmpty()) {
                $memberIds = User::query()->inRandomOrder()->limit(3)->pluck('id');
            }

            // Ensure a reasonable folder structure exists
            $folders = $org->folders()->get();
            if ($folders->isEmpty()) {
                $ownerId = $memberIds->first() ?? User::query()->value('id');
                $createFolder = function (string $systemName, ?string $parentId, string $color, string $icon, int $position) use ($org, $ownerId): Folder {
                    $folder = new Folder();
                    $folder->organization_id = $org->id;
                    $folder->parent_id = $parentId;
                    $folder->system_name = $systemName;
                    $folder->system_named_at = now();
                    $folder->display_name = null;
                    $folder->color = $color;
                    $folder->icon = $icon;
                    $folder->position = $position;
                    $folder->created_by = $ownerId;
                    $folder->save();
                    return $folder;
                };

                $rootIdeas = $createFolder('Ideas', null, '#3b82f6', 'lightbulb', 1);
                $rootCampaigns = $createFolder('Campaigns', null, '#10b981', 'flag', 2);
                $createFolder('Q1', (string) $rootCampaigns->id, '#f59e0b', 'calendar', 1);
                $createFolder('Q2', (string) $rootCampaigns->id, '#a855f7', 'calendar', 2);
                $folders = $org->folders()->get();
            }

            // Ensure a healthy set of tags for this org
            $existingTags = $org->tags()->pluck('name')->map(fn($n) => strtolower($n));
            foreach ($tagCatalog as $t) {
                if (!$existingTags->contains(strtolower($t['name']))) {
                    Tag::create([
                        'organization_id' => $org->id,
                        'name' => $t['name'],
                        'color' => $t['color'],
                        'created_by' => $memberIds->random(),
                    ]);
                }
            }
            $tags = $org->tags()->get();

            // Target: 100 bookmarks per organization (top up only)
            $current = $org->bookmarks()->count();
            $toCreate = max(0, 100 - $current);
            if ($toCreate === 0) {
                continue;
            }

            for ($i = 0; $i < $toCreate; $i++) {
                $platform = Arr::random([
                    // Weighted distribution
                    'instagram','instagram','instagram',
                    'tiktok','tiktok',
                    'youtube','youtube',
                    'twitter','twitter',
                    'linkedin',
                    'pinterest',
                    'other',
                ]);

                $handle = fake()->userName();
                $id = (string) fake()->numberBetween(10000000, 99999999);
                $slug = str_replace(' ', '-', strtolower(fake()->words(3, true)));

                $url = match ($platform) {
                    'instagram' => "https://www.instagram.com/p/{$slug}/",
                    'tiktok' => "https://www.tiktok.com/@{$handle}/video/{$id}",
                    'youtube' => "https://www.youtube.com/watch?v=" . fake()->bothify('???????????'),
                    'twitter' => "https://twitter.com/{$handle}/status/{$id}",
                    'linkedin' => "https://www.linkedin.com/posts/{$handle}_{$slug}-{$id}",
                    'pinterest' => "https://www.pinterest.com/pin/{$id}/",
                    default => fake()->url(),
                };

                $meta = [
                    'likes' => fake()->numberBetween(0, 150000),
                    'views' => fake()->numberBetween(100, 2000000),
                    'shares' => fake()->numberBetween(0, 50000),
                    'comments' => fake()->numberBetween(0, 15000),
                ];

                $bookmark = Bookmark::create([
                    'organization_id' => $org->id,
                    'folder_id' => fake()->boolean(75) ? $folders->random()->id : null,
                    'created_by' => $memberIds->random(),
                    'title' => ucfirst($platform) . ': ' . fake()->sentence(4),
                    'description' => fake()->optional(0.7)->paragraph(),
                    'url' => $url,
                    'image_url' => fake()->optional(0.5)->imageUrl(1200, 628, null, true),
                    'favicon_url' => fake()->optional(0.8)->imageUrl(32, 32, 'favicon', true),
                    'platform' => $platform,
                    'platform_metadata' => $meta,
                    'type' => Arr::random(['inspiration','reference','competitor','trend']),
                    'is_favorite' => fake()->boolean(18),
                    'is_archived' => fake()->boolean(8),
                ]);

                // Attach 0–3 tags
                $attach = $tags->count() ? $tags->random(fake()->numberBetween(0, min(3, $tags->count())))->pluck('id')->all() : [];
                if (!empty($attach)) {
                    $bookmark->tags()->syncWithoutDetaching($attach);
                }
            }
        }
    }
}

