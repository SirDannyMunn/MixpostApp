<?php

namespace App\Jobs;

use App\Models\Bookmark;
use App\Models\KnowledgeItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class BookmarkToKnowledgeItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $bookmarkId,
        public string $userId,
        public string $organizationId,
    ) {}

    public function handle(): void
    {
        $bookmark = Bookmark::find($this->bookmarkId);
        if (!$bookmark) {
            return;
        }

        // Resolve strictly from internal bookmark fields (title + description)
        $title = trim((string) ($bookmark->title ?? ''));
        $desc = trim((string) ($bookmark->description ?? ''));
        $raw = $desc;
        if ($title !== '') {
            $raw = $raw !== '' ? ($title . "\n\n" . $raw) : $title;
        }
        if (trim($raw) === '') {
            // In debug, crash to surface contract breaches
            if (config('app.debug')) {
                throw new \RuntimeException('No internal content for bookmark');
            }
            Log::warning('bookmark.ingest.no_internal_content', ['bookmark_id' => $bookmark->id]);
            return;
        }

        $hash = hash('sha256', $this->normalizeForHash($raw));

        // Guardrail 1: Duplicate ingestion prevention
        // Prefer exact pair match (source_id + hash) within org; fallback to hash-only
        $existing = KnowledgeItem::query()
            ->where('organization_id', $this->organizationId)
            ->where('source', 'bookmark')
            ->where('source_id', $bookmark->id)
            ->where('raw_text_sha256', $hash)
            ->first();
        if (!$existing) {
            $existing = KnowledgeItem::query()
                ->where('organization_id', $this->organizationId)
                ->where('raw_text_sha256', $hash)
                ->first();
        }
        if ($existing) {
            Log::info('bookmark.ingest.duplicate_skipped', [
                'bookmark_id' => $bookmark->id,
                'existing_knowledge_item_id' => $existing->id,
            ]);
            return;
        }

        $item = KnowledgeItem::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => $this->organizationId,
            'user_id' => $this->userId,
            'type' => 'excerpt',
            'source' => 'bookmark',
            'source_id' => $bookmark->id,
            'source_platform' => $bookmark->platform ?? null,
            'title' => $title ?: null,
            'raw_text' => $raw,
            'raw_text_sha256' => $hash,
            'metadata' => [
                'source_url' => $bookmark->url,
                'image_url' => $bookmark->image_url,
                'favicon_url' => $bookmark->favicon_url,
            ],
            // Guardrail 2: default low confidence for raw external excerpts
            'confidence' => 0.3,
            'ingested_at' => now(),
        ]);

        if (trim((string) $item->raw_text) === '') {
            throw new \RuntimeException('InvariantViolation: KnowledgeItem created without raw_text');
        }

        // Chain pipeline: normalize -> chunk -> embed -> voice -> facts
        Bus::chain([
            new NormalizeKnowledgeItemJob($item->id),
            new ChunkKnowledgeItemJob($item->id),
            new EmbedKnowledgeChunksJob($item->id),
            new ExtractVoiceTraitsJob($item->id),
            new ExtractBusinessFactsJob($item->id),
        ])->dispatch();

        Log::info('bookmark.ingested', [
            'bookmark_id' => $bookmark->id,
            'knowledge_item_id' => $item->id,
            'chars' => mb_strlen($raw),
        ]);
    }

    private function normalizeForHash(string $text): string
    {
        $t = trim($text);
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        return $t;
    }
}
