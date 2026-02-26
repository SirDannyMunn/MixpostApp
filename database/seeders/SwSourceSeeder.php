<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Organization;

class SwSourceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Based on social-watcher-apify.php profiles config
     */
    public function run(): void
    {
        $org = Organization::query()->first() ?? Organization::factory()->create();
        $now = now();

        $sw_sources = [
            // YouTube Videos
            [
                'id' => 1,
                'name' => 'YouTube - indexsy channel',
                'type' => 'profile',
                'platform' => 'youtube',
                'subtype' => 'videos',
                'platform_identifier' => '@indexsy',
                'apify_actor_id' => 'apidojo~youtube-scraper',
                'apify_config' => json_encode([
                    'customMapFunction' => '(object) => { return {...object} }',
                    'duration' => 'all',
                    'features' => 'all',
                    'getTrending' => false,
                    'includeShorts' => false,
                    'keywords' => ['pixel art'],
                    'maxItems' => 1000,
                    'sort' => 'r',
                    'uploadDate' => 'all',
                    'youtubeHandles' => ['@indexsy'],
                ]),
                'url' => null,
                'is_active' => true,
                'meta' => json_encode([]),
                'ingestion_profile' => 'youtube_videos',
                'ingestion_overrides' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            
            // YouTube Search
            [
                'id' => 2,
                'name' => 'YouTube Search - SEO',
                'type' => 'search',
                'platform' => 'youtube',
                'subtype' => 'search',
                'platform_identifier' => 'SEO',
                'apify_actor_id' => 'apidojo~youtube-scraper',
                'apify_config' => json_encode([
                    'customMapFunction' => '(object) => { return {...object} }',
                    'duration' => 'all',
                    'features' => 'all',
                    'getTrending' => false,
                    'includeShorts' => false,
                    'keywords' => ['SEO'],
                    'maxItems' => 25,
                    'sort' => 'r',
                    'uploadDate' => 'all',
                ]),
                'url' => null,
                'is_active' => true,
                'meta' => json_encode(['persist' => false]),
                'ingestion_profile' => 'youtube_search_alternative',
                'ingestion_overrides' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            
            // LinkedIn Profile Posts
            [
                'id' => 3,
                'name' => 'LinkedIn - Jacky Chou',
                'type' => 'profile',
                'platform' => 'linkedin',
                'subtype' => 'posts',
                'platform_identifier' => 'jacky-chou',
                'apify_actor_id' => 'harvestapi~linkedin-profile-posts',
                'apify_config' => json_encode([
                    'includeQuotePosts' => true,
                    'includeReposts' => true,
                    'maxComments' => 5,
                    'maxPosts' => 5,
                    'maxReactions' => 5,
                    'scrapeComments' => false,
                    'scrapeReactions' => false,
                    'targetUrls' => ['https://www.linkedin.com/in/jacky-chou/'],
                ]),
                'url' => 'https://www.linkedin.com/in/jacky-chou/',
                'is_active' => true,
                'meta' => json_encode([]),
                'ingestion_profile' => 'linkedin_profile_posts',
                'ingestion_overrides' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            
            // Instagram Profile Posts
            [
                'id' => 4,
                'name' => 'Instagram - indexsy',
                'type' => 'profile',
                'platform' => 'instagram',
                'subtype' => 'posts',
                'platform_identifier' => 'indexsy',
                'apify_actor_id' => 'apidojo~instagram-scraper',
                'apify_config' => json_encode([
                    'customMapFunction' => '(object) => { return {...object} }',
                    'maxItems' => 5,
                    'startUrls' => ['https://www.instagram.com/indexsy/'],
                    'until' => '2023-12-15',
                ]),
                'url' => 'https://www.instagram.com/indexsy/',
                'is_active' => true,
                'meta' => json_encode([]),
                'ingestion_profile' => 'instagram_profile_posts',
                'ingestion_overrides' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            
            // X (Twitter) Profile
            [
                'id' => 5,
                'name' => 'X - iamgdsa',
                'type' => 'profile',
                'platform' => 'x',
                'subtype' => 'profile',
                'platform_identifier' => 'iamgdsa',
                'apify_actor_id' => 'danek~twitter-scraper-ppr',
                'apify_config' => json_encode([
                    'max_posts' => 2,
                    'username' => 'iamgdsa',
                    'search_type' => 'Top',
                ]),
                'url' => null,
                'is_active' => true,
                'meta' => json_encode([]),
                'ingestion_profile' => 'x_profile',
                'ingestion_overrides' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            
            // Instagram Profile (Bio scraper)
            [
                'id' => 6,
                'name' => 'Instagram Bio - indexsy',
                'type' => 'profile',
                'platform' => 'instagram',
                'subtype' => 'profile',
                'platform_identifier' => 'indexsy',
                'apify_actor_id' => 'coderx~instagram-profile-scraper-bio-posts',
                'apify_config' => json_encode([
                    'usernames' => ['indexsy'],
                ]),
                'url' => null,
                'is_active' => true,
                'meta' => json_encode([]),
                'ingestion_profile' => 'instagram_profile',
                'ingestion_overrides' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            
            // X Search
            [
                'id' => 7,
                'name' => 'X Search - SEO',
                'type' => 'search',
                'platform' => 'x',
                'subtype' => 'search',
                'platform_identifier' => 'SEO',
                'apify_actor_id' => 'danek~twitter-scraper-ppr',
                'apify_config' => json_encode([
                    'max_posts' => 2,
                    'query' => 'SEO',
                ]),
                'url' => null,
                'is_active' => true,
                'meta' => json_encode([]),
                'ingestion_profile' => 'x_search',
                'ingestion_overrides' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            
            // X Trends
            [
                'id' => 8,
                'name' => 'X Trends - UK',
                'type' => 'search',
                'platform' => 'x',
                'subtype' => 'trends',
                'platform_identifier' => 'uk',
                'apify_actor_id' => 'danek~twitter-scraper-ppr',
                'apify_config' => json_encode([
                    'country' => 'uk',
                    'max_posts' => 2,
                ]),
                'url' => null,
                'is_active' => true,
                'meta' => json_encode([]),
                'ingestion_profile' => 'x_trends',
                'ingestion_overrides' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            
            // YouTube Transcript
            [
                'id' => 9,
                'name' => 'YouTube Transcript',
                'type' => 'transcript',
                'platform' => 'youtube',
                'subtype' => 'transcript',
                'platform_identifier' => 'kW_dSkoj1ZU',
                'apify_actor_id' => 'scrape-creators~best-youtube-transcripts-scraper',
                'apify_config' => json_encode([
                    'videoUrls' => ['https://www.youtube.com/watch?v=kW_dSkoj1ZU&pp=ugUEEgJlbg%3D%3D'],
                ]),
                'url' => 'https://www.youtube.com/watch?v=kW_dSkoj1ZU',
                'is_active' => true,
                'meta' => json_encode([]),
                'ingestion_profile' => 'youtube_transcript',
                'ingestion_overrides' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            
            // X Post Details
            [
                'id' => 10,
                'name' => 'X Post Details',
                'type' => 'twitter',
                'platform' => 'x',
                'subtype' => 'post',
                'platform_identifier' => '2002370533959098875',
                'apify_actor_id' => 'danek~twitter-scraper-ppr',
                'apify_config' => json_encode([
                    'lookup_post_ids' => ['2002370533959098875'],
                    'max_posts' => 2,
                ]),
                'url' => null,
                'is_active' => true,
                'meta' => json_encode([]),
                'ingestion_profile' => 'x_post_details',
                'ingestion_overrides' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            
            // TikTok Search
            [
                'id' => 11,
                'name' => 'TikTok Search - SEO',
                'type' => 'search',
                'platform' => 'tiktok',
                'subtype' => 'search',
                'platform_identifier' => 'seo',
                'apify_actor_id' => 'clockworks/free-tiktok-scraper',
                'apify_config' => json_encode([
                    'excludePinnedPosts' => false,
                    'resultsPerPage' => 50,
                    'searchQueries' => ['seo'],
                    'shouldDownloadCovers' => false,
                    'shouldDownloadSlideshowImages' => false,
                    'shouldDownloadSubtitles' => true,
                    'shouldDownloadVideos' => true,
                ]),
                'url' => null,
                'is_active' => true,
                'meta' => json_encode([]),
                'ingestion_profile' => 'tiktok_search',
                'ingestion_overrides' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            
            // TikTok Video Transcript
            [
                'id' => 12,
                'name' => 'TikTok Video Transcript',
                'type' => 'transcript',
                'platform' => 'tiktok',
                'subtype' => 'transcript',
                'platform_identifier' => '7248180517596597531',
                'apify_actor_id' => 'ingeniela/tiktok-video-transcriber',
                'apify_config' => json_encode([
                    'detectLanguage' => true,
                    'downloadVideo' => false,
                    'enableTranscription' => true,
                    'extractFirstFrame' => true,
                    'generateTimestamps' => true,
                    'tiktokUrl' => 'https://www.tiktok.com/@maryannedamarzo/video/7248180517596597531?q=seo&t=1768306801044',
                ]),
                'url' => 'https://www.tiktok.com/@maryannedamarzo/video/7248180517596597531',
                'is_active' => true,
                'meta' => json_encode([]),
                'ingestion_profile' => 'tiktok_video_transcript',
                'ingestion_overrides' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        $p = config('social-watcher.table_prefix', 'sw_');

        $rows = array_map(function (array $row) use ($org) {
            $row['organization_id'] ??= $org->id;
            return $row;
        }, $sw_sources);

        DB::table($p . 'sources')->upsert($rows, ['id']);
        
        $this->command->info('Seeded ' . count($rows) . ' social watcher sources from apify profiles.');
    }
}
