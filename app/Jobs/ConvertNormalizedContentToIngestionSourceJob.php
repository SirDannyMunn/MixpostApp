<?php

namespace App\Jobs;

use App\Models\BusinessFact;
use App\Models\IngestionSource;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeItem;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LaundryOS\SocialWatcher\Models\NormalizedContent;

class ConvertNormalizedContentToIngestionSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $normalizedContentId,
        public string $organizationId,
        public ?string $userId = null,
        public bool $force = false,
        public ?string $titleOverride = null,
    ) {}

    public function handle(): array
    {
        if (!Str::isUuid($this->normalizedContentId)) {
            throw new \InvalidArgumentException('normalizedContentId must be a UUID');
        }
        if (!Str::isUuid($this->organizationId)) {
            throw new \InvalidArgumentException('organizationId must be a UUID');
        }
        if ($this->userId !== null && !Str::isUuid($this->userId)) {
            throw new \InvalidArgumentException('userId must be a UUID');
        }

        $org = Organization::query()->findOrFail($this->organizationId);

        $userId = $this->userId;
        if (!$userId) {
            $member = $org->members()->first();
            $userId = $member?->id;
        }
        if (!$userId) {
            $userId = User::query()->value('id');
        }
        if (!$userId) {
            throw new \RuntimeException('No user available to attribute ingestion source');
        }

        $normalized = NormalizedContent::query()->findOrFail($this->normalizedContentId);

        $raw = $this->composeRawText($normalized);
        $title = $this->composeTitle($normalized);

        $sourceId = $this->makeCompositeSourceId($this->organizationId, $this->normalizedContentId);

        $attrs = [
            'organization_id' => $org->id,
            'user_id' => $userId,
            'source_type' => 'text',
            'source_id' => $sourceId,
            'origin' => 'social_watcher',
            'platform' => (string) ($normalized->platform ?? ''),
            'raw_url' => (string) ($normalized->url ?? ''),
            'raw_text' => $raw,
            'mime_type' => null,
            'dedup_hash' => IngestionSource::dedupHashFromText($raw),
            'status' => 'pending',
        ];

        if (Schema::hasColumn('ingestion_sources', 'title')) {
            $attrs['title'] = $title;
        }

        if (Schema::hasColumn('ingestion_sources', 'metadata')) {
            $attrs['metadata'] = $this->composeMetadata($normalized);
        }

        // Unique constraint is on (source_type, source_id) so this is safe and idempotent.
        $src = IngestionSource::query()->firstOrCreate(
            [
                'source_type' => 'text',
                'source_id' => $sourceId,
            ],
            $attrs
        );

        // If it already existed, refresh fields to reflect latest normalized data.
        $src->fill($attrs);
        $src->save();

        if ($this->force) {
            $this->purgeDerived($src);
            $src->status = 'pending';
            $src->error = null;
            $src->dedup_reason = null;
            $src->save();
        }

        // Dispatch processing unless it's already running.
        if ($src->status !== 'processing' && ($src->status !== 'completed' || $this->force)) {
            ProcessIngestionSourceJob::dispatch((string) $src->id, $this->force);
        }

        return [
            'ingestion_source_id' => (string) $src->id,
            'normalized_content_id' => (string) $normalized->id,
        ];
    }

    private function makeCompositeSourceId(string $organizationId, string $normalizedContentId): string
    {
        return 'sw_norm:' . $organizationId . ':' . $normalizedContentId;
    }

    private function composeTitle(NormalizedContent $normalized): ?string
    {
        $title = $this->titleOverride;
        if ($title === null || trim($title) === '') {
            $title = (string) ($normalized->title ?? '');
        }

        $title = trim((string) $title);
        if ($title !== '') {
            return mb_substr($title, 0, 500);
        }

        $author = trim((string) ($normalized->author_name ?? ''));
        $platform = trim((string) ($normalized->platform ?? ''));
        $published = $normalized->published_at ? $normalized->published_at->format('Y-m-d') : null;

        $fallback = trim(implode(' ', array_filter([
            $author !== '' ? $author : null,
            $platform !== '' ? '(' . $platform . ')' : null,
            $published ? $published : null,
        ])));

        return $fallback !== '' ? mb_substr($fallback, 0, 500) : null;
    }

    private function composeRawText(NormalizedContent $normalized): string
    {
        $parts = [];

        $title = trim((string) ($normalized->title ?? ''));
        if ($title !== '') {
            $parts[] = $title;
        }

        $text = trim((string) ($normalized->text ?? ''));
        if ($text !== '') {
            $parts[] = $text;
        }

        // Fallback: ensure we always have something non-empty for text ingestion
        if (empty($parts)) {
            $fallback = [];
            $url = trim((string) ($normalized->url ?? ''));
            $author = trim((string) ($normalized->author_name ?? ''));
            $externalId = trim((string) ($normalized->external_id ?? ''));

            if ($author !== '') $fallback[] = 'Author: ' . $author;
            if ($externalId !== '') $fallback[] = 'External ID: ' . $externalId;
            if ($url !== '') $fallback[] = 'URL: ' . $url;

            $parts[] = implode("\n", $fallback);
        }

        return trim(implode("\n\n", array_filter($parts)));
    }

    private function composeMetadata(NormalizedContent $normalized): array
    {
        $meta = [
            'social_watcher' => [
                'normalized_content_id' => (string) $normalized->id,
                'source_id' => $normalized->source_id,
                'platform' => $normalized->platform,
                'external_id' => $normalized->external_id,
                'url' => $normalized->url,
                'author_name' => $normalized->author_name,
                'author_username' => $normalized->author_username,
                'published_at' => $normalized->published_at?->toISOString(),
                'media_type' => $normalized->media_type,
                'likes' => $normalized->likes,
                'comments' => $normalized->comments,
                'shares' => $normalized->shares,
                'views' => $normalized->views,
                'engagement_score' => $normalized->engagement_score,
                'velocity_score' => $normalized->velocity_score,
                'raw_reference_id' => $normalized->raw_reference_id,
                'normalization_version' => $normalized->normalization_version,
                'metadata' => $normalized->metadata,
            ],
        ];

        // Strip nulls to keep payload tidy
        return $this->arrayFilterRecursive($meta);
    }

    private function arrayFilterRecursive(array $value): array
    {
        $out = [];
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $v = $this->arrayFilterRecursive($v);
                if ($v === []) continue;
                $out[$k] = $v;
                continue;
            }
            if ($v === null) continue;
            $out[$k] = $v;
        }
        return $out;
    }

    private function purgeDerived(IngestionSource $src): void
    {
        $kiIds = KnowledgeItem::query()
            ->where('organization_id', $src->organization_id)
            ->where('ingestion_source_id', $src->id)
            ->pluck('id');

        if ($kiIds->isEmpty()) return;

        DB::transaction(function () use ($kiIds) {
            KnowledgeChunk::query()->whereIn('knowledge_item_id', $kiIds)->delete();
            BusinessFact::query()->whereIn('source_knowledge_item_id', $kiIds)->delete();
            KnowledgeItem::query()->whereIn('id', $kiIds)->delete();
        });
    }
}
