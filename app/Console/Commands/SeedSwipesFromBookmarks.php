<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use App\Models\KnowledgeItem;
use App\Models\SwipeItem;
use App\Jobs\ExtractSwipeStructureJob;

class SeedSwipesFromBookmarks extends Command
{
    protected $signature = 'seed:swipes {--count=5 : Number of items to promote}';
    protected $description = 'Promote random KnowledgeItems (from bookmarks) into SwipeItems and trigger structure extraction.';

    public function handle(): int
    {
        $count = (int) $this->option('count');
        if ($count < 1) $count = 5;

        $bookmarks = KnowledgeItem::query()
            ->inRandomOrder()
            ->limit($count)
            ->get();

        if ($bookmarks->isEmpty()) {
            $this->warn('No KnowledgeItems found to promote.');
            return self::SUCCESS;
        }

        foreach ($bookmarks as $bookmark) {
            $raw = (string) ($bookmark->raw_text ?? '');
            if ($raw === '') {
                continue;
            }
            $hash = hash('sha256', $raw);
            $sourceUrl = null;
            if (is_array($bookmark->metadata) && isset($bookmark->metadata['source_url'])) {
                $sourceUrl = $bookmark->metadata['source_url'];
            }

            $swipe = SwipeItem::create([
                'organization_id' => $bookmark->organization_id,
                'user_id'         => $bookmark->user_id,
                'platform'        => $bookmark->source_platform ?? 'web',
                'raw_text'        => $raw,
                'raw_text_sha256' => $hash,
                'source_url'      => $sourceUrl,
                'author_handle'   => 'Imported',
                'created_at'      => now(),
            ]);

            dispatch(new ExtractSwipeStructureJob($swipe->id));
            $this->info("Promoted KnowledgeItem {$bookmark->id} to Swipe {$swipe->id}");
        }

        return self::SUCCESS;
    }
}