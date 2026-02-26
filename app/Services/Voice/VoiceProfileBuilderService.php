<?php

namespace App\Services\Voice;

use App\Models\VoiceProfile;
use App\Models\VoiceProfilePost;
use App\Services\OpenRouterService;
use App\Services\Voice\Prompts\VoiceProfileExtractV2Prompt;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LaundryOS\SocialWatcher\Models\ContentNode;

class VoiceProfileBuilderService
{
    private ?array $lastConsistencyMetrics = null;

    public function __construct(private OpenRouterService $ai)
    {
    }

    /**
     * Regenerate a voice profile from its attached posts and optional filters.
     * Returns the saved VoiceProfile with updated traits/confidence/sample_size.
     */
    public function rebuild(VoiceProfile $profile, array $filters = []): VoiceProfile
    {
        $schemaVersion = $filters['schema_version'] ?? '2.0';
        
        $candidates = $this->collectCandidates($profile, $filters);
        if ($candidates->isEmpty()) {
            throw new \RuntimeException('insufficient data');
        }

        $texts = $this->prepareTexts($candidates);
        if (empty($texts)) {
            throw new \RuntimeException('insufficient data');
        }

        if ($schemaVersion === '2.0') {
            $traits = $this->rebuildV2($texts);
            $consistencyMetrics = $this->lastConsistencyMetrics ?? [];
        } else {
            // Legacy v1 extraction
            $batchSignals = $this->extractBatchSignals($texts);
            $traits = $this->consolidateTraits($batchSignals);
            $consistencyMetrics = [];
        }

        // Confidence and stats
        $profile->sample_size = count($texts);
        $profile->confidence = $this->computeConfidence($candidates, $consistencyMetrics);
        $profile->traits = $traits;
        $profile->traits_schema_version = $schemaVersion;
        $profile->refreshTraitsPreview();
        $profile->refreshStylePreview();
        $profile->status = 'ready';
        $profile->updated_at = now();
        $profile->save();

        return $profile;
    }

    protected function collectCandidates(VoiceProfile $profile, array $filters): Collection
    {
        // Priority: explicitly attached posts
        $attachedIds = VoiceProfilePost::query()
            ->where('voice_profile_id', $profile->id)
            ->pluck('content_node_id')
            ->all();

        $contentNodeTable = (new ContentNode())->getTable();
        $voicePostsTable = (new VoiceProfilePost())->getTable();
        $contentNodeConn = (new ContentNode())->getConnectionName();
        $voicePostsConn = (new VoiceProfilePost())->getConnectionName();

        $q = ContentNode::query();
        if (!empty($attachedIds)) {
            if ($contentNodeConn === $voicePostsConn) {
                // Use a join instead of whereIn so we always scope strictly to the profile's training set
                // and avoid issues with large IN clauses.
                $q->join($voicePostsTable, function ($join) use ($voicePostsTable, $contentNodeTable) {
                    // content_node_id is stored as uuid.
                    $join->on($voicePostsTable . '.content_node_id', '=', $contentNodeTable . '.id');
                })
                    ->where($voicePostsTable . '.voice_profile_id', $profile->id)
                    ->select([$contentNodeTable . '.*']);
            } else {
                // If these models are backed by different connections, a SQL join would fail.
                $q->whereIn($contentNodeTable . '.id', $attachedIds);
            }
        } else {
            // If nothing attached, allow optional source_id-based selection
            if (!empty($filters['source_id'])) {
                $q->where($contentNodeTable . '.source_id', (int) $filters['source_id']);
            } else {
                // No data to build from
                return collect();
            }
        }

        // Optional filters
        if (!empty($filters['min_engagement'])) {
            $q->where($contentNodeTable . '.like_count', '>=', (int) $filters['min_engagement']);
        }
        if (!empty($filters['start_date'])) {
            $q->where($contentNodeTable . '.published_at', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $q->where($contentNodeTable . '.published_at', '<=', $filters['end_date']);
        }
        if (!empty($filters['exclude_replies'])) {
            $q->where(function ($qq) {
                $qq->whereNull('metadata->is_reply')
                   ->orWhere('metadata->is_reply', false);
            });
        }

        $q->orderByDesc($contentNodeTable . '.like_count')->orderByDesc($contentNodeTable . '.published_at');

        $limit = (int) ($filters['limit'] ?? 200);
        $items = $q->limit(max(1, min($limit, 500)))->get();
        return $items;
    }

    protected function prepareTexts(Collection $items): array
    {
        $out = [];
        foreach ($items as $it) {
            // Prefer canonical text; fall back to title/metadata for platforms that store body elsewhere.
            $text = trim((string) ($it->text ?? ''));
            if ($text === '') {
                $text = trim((string) ($it->title ?? ''));
            }

            if ($text === '') {
                $meta = is_array($it->metadata ?? null) ? (array) $it->metadata : [];
                $fallbackPaths = [
                    'caption',
                    'description',
                    'body',
                    'content',
                    'full_text',
                    'text',
                    'post_text',
                    'tweet.full_text',
                    'tweet.text',
                    'linkedin.text',
                    'youtube.description',
                    'instagram.caption',
                ];

                foreach ($fallbackPaths as $path) {
                    $v = Arr::get($meta, $path);
                    if (is_string($v)) {
                        $v = trim($v);
                        if ($v !== '') {
                            $text = $v;
                            break;
                        }
                    }
                }
            }

            $text = $this->cleanText($text);
            if ($text !== '') $out[] = $text;
        }
        return $out;
    }

    protected function cleanText(string $text): string
    {
        // Remove URLs
        $text = preg_replace('/https?:\/\/\S+/i', '', $text);
        // Strip hashtags if standalone
        $text = preg_replace('/(^|\s)#(\w+)/', '$1$2', $text);
        // Normalize whitespace & emoji spacing
        $text = trim(preg_replace('/\s+/', ' ', (string) $text));
        return $text;
    }

    protected function extractBatchSignals(array $texts): array
    {
        $batches = array_chunk($texts, 40);
        $signals = [];
        foreach ($batches as $batch) {
            $prompt = $this->buildBatchPrompt($batch);
            $res = $this->ai->chatJSON([
                ['role' => 'system', 'content' => 'You extract writing voice signals. Return JSON only.'],
                ['role' => 'user', 'content' => $prompt],
            ], [
                'temperature' => 0.1,
                'max_tokens' => 1200,
            ]);
            if (is_array($res) && !empty($res)) {
                $signals[] = $res;
            }
        }
        return $signals;
    }

    protected function buildBatchPrompt(array $batch): string
    {
        $joined = implode("\n---\n", array_map(fn($t) => mb_substr($t, 0, 400), $batch));
        return "From these posts, infer voice signals. Return JSON with keys: tone:[string], persona:string|null, formality:string|null, sentence_length:string|null, paragraph_density:string|null, pacing:string|null, emotional_intensity:string|null, style_signatures:[string], do_not_do:[string].\n\nPosts:\n" . $joined;
    }

    protected function consolidateTraits(array $batchSignals): array
    {
        // Merge heuristics, then finalize via a consolidation prompt to canonical schema
        $merged = [
            'tone' => [],
            'style_signatures' => [],
            'do_not_do' => [],
        ];
        foreach ($batchSignals as $sig) {
            foreach (['tone','style_signatures','do_not_do'] as $key) {
                if (!empty($sig[$key]) && is_array($sig[$key])) {
                    $merged[$key] = array_merge($merged[$key], array_filter(array_map('strval', $sig[$key])));
                }
            }
        }
        $merged['tone'] = array_values(array_unique(array_map('strtolower', $merged['tone'])));
        $merged['style_signatures'] = array_values(array_unique($merged['style_signatures']));
        $merged['do_not_do'] = array_values(array_unique($merged['do_not_do']));

        $prompt = "Create a canonical voice profile JSON from these signals. Use this schema strictly: {\n  description: string,\n  tone: string[],\n  persona: string|null,\n  formality: string|null,\n  sentence_length: 'short'|'medium'|'long'|null,\n  paragraph_density: 'tight'|'normal'|'airy'|null,\n  pacing: 'slow'|'medium'|'fast'|null,\n  emotional_intensity: 'low'|'medium'|'high'|null,\n  style_signatures: string[],\n  do_not_do: string[],\n  keyword_bias: string[],\n  reference_examples: string[]\n}.\nReturn only JSON.\nSignals:" . json_encode($batchSignals + [$merged], JSON_UNESCAPED_SLASHES);

        $res = $this->ai->chatJSON([
            ['role' => 'system', 'content' => 'You are a strict JSON generator.'],
            ['role' => 'user', 'content' => $prompt],
        ], [
            'temperature' => 0.1,
            'max_tokens' => 2000,
        ]);

        // Guard: ensure minimal required keys
        $traits = is_array($res) ? $res : [];
        $traits['tone'] = array_values(array_unique(array_map('strval', Arr::get($traits, 'tone', []))));
        $traits['style_signatures'] = array_values(array_unique(array_map('strval', Arr::get($traits, 'style_signatures', []))));
        $traits['do_not_do'] = array_values(array_unique(array_map('strval', Arr::get($traits, 'do_not_do', []))));
        $traits['reference_examples'] = array_values(array_map('strval', Arr::get($traits, 'reference_examples', [])));
        $traits['keyword_bias'] = array_values(array_map('strval', Arr::get($traits, 'keyword_bias', [])));
        $traits['description'] = (string) ($traits['description'] ?? '');
        return $traits;
    }

    protected function computeConfidence(Collection $items, array $consistencyMetrics = []): float
    {
        // Simple platform-based base weights + sample size factor
        $weights = [
            'twitter' => 0.25,
            'x' => 0.25,
            'youtube' => 0.25,
            'linkedin' => 0.18,
            'instagram' => 0.15,
            'generic' => 0.10,
        ];
        $base = 0.0;
        $n = max(1, $items->count());
        foreach ($items as $it) {
            $p = strtolower((string) ($it->platform ?? 'generic'));
            $base += $weights[$p] ?? $weights['generic'];
        }
        $base = $base / $n; // average base weight

        $sampleBoost = log(max(1, $n) / 20, 10); // around 0 at 20 samples
        $sampleBoost = max(-0.3, min(0.3, $sampleBoost));

        // V2: add consistency bonus if available
        $consistencyBonus = 0.0;
        if (!empty($consistencyMetrics['consistency'])) {
            $consistencyBonus = $consistencyMetrics['consistency'] * 0.25;
        }

        $confidence = 0.15 + (0.55 * $base) + (0.20 * $sampleBoost) + $consistencyBonus;
        $confidence = max(0.1, min(0.98, $confidence));
        return round($confidence, 2);
    }

    /**
     * V2 rebuild: extract traits using v2 schema and merge.
     */
    protected function rebuildV2(array $texts): array
    {
        if (count($texts) < 10) {
            throw new \RuntimeException('insufficient data: need at least 10 posts');
        }

        $totalChars = array_sum(array_map('strlen', $texts));
        if ($totalChars < 2000) {
            throw new \RuntimeException('insufficient data: need at least 2000 characters');
        }

        $batchTraits = [];
        $batches = array_chunk($texts, 30);

        foreach ($batches as $batch) {
            try {
                $extracted = $this->extractTraitsV2ForBatch($batch);
                
                // Validate
                $validation = VoiceTraitsValidator::validate($extracted);
                if (!$validation['valid']) {
                    Log::warning('Voice profile v2 batch validation failed', [
                        'errors' => $validation['errors'],
                    ]);
                    
                    // Attempt repair
                    $extracted = $this->attemptRepair($extracted, $validation['errors']);
                    $validation = VoiceTraitsValidator::validate($extracted);
                    
                    if (!$validation['valid']) {
                        Log::error('Voice profile v2 batch repair failed, skipping batch');
                        continue;
                    }
                }
                
                $batchTraits[] = $extracted;
            } catch (\Exception $e) {
                Log::error('Voice profile v2 batch extraction failed', [
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        if (empty($batchTraits)) {
            throw new \RuntimeException('extraction_failed: all batches failed');
        }

        // Merge and compute consistency
        $merged = VoiceTraitsMerger::merge($batchTraits);
        $this->lastConsistencyMetrics = VoiceTraitsMerger::computeConsistencyMetrics($batchTraits);

        return $merged;
    }

    /**
     * Extract v2 traits from a single batch of posts.
     */
    protected function extractTraitsV2ForBatch(array $posts): array
    {
        $messages = VoiceProfileExtractV2Prompt::build($posts);
        
        $result = $this->ai->chatJSON([
            ['role' => 'system', 'content' => $messages['system']],
            ['role' => 'user', 'content' => $messages['user']],
        ], [
            'temperature' => 0.1,
            'max_tokens' => 3000,
        ]);

        if (!is_array($result) || empty($result)) {
            throw new \RuntimeException('LLM returned empty response');
        }

        return $result;
    }

    /**
     * Attempt to repair invalid traits by filling in defaults.
     */
    protected function attemptRepair(array $traits, array $errors): array
    {
        // Ensure schema_version
        if (empty($traits['schema_version'])) {
            $traits['schema_version'] = '2.0';
        }

        // Ensure description
        if (empty($traits['description'])) {
            $traits['description'] = 'Voice profile';
        }

        // Ensure do_not_do has minimum items
        if (empty($traits['do_not_do']) || !is_array($traits['do_not_do']) || count($traits['do_not_do']) < 5) {
            $traits['do_not_do'] = array_merge(
                $traits['do_not_do'] ?? [],
                ['use excessive jargon', 'write generic content', 'ignore audience context', 'be inconsistent', 'lack authenticity']
            );
            $traits['do_not_do'] = array_unique(array_slice($traits['do_not_do'], 0, 10));
        }

        // Ensure style_signatures has minimum items
        if (empty($traits['style_signatures']) || !is_array($traits['style_signatures']) || count($traits['style_signatures']) < 3) {
            $traits['style_signatures'] = array_merge(
                $traits['style_signatures'] ?? [],
                ['direct communication', 'clear structure', 'audience-focused']
            );
            $traits['style_signatures'] = array_unique(array_slice($traits['style_signatures'], 0, 10));
        }

        return $traits;
    }
}
