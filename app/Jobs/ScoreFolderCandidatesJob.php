<?php

namespace App\Jobs;

use App\Models\Folder;
use App\Models\IngestionSource;
use App\Models\IngestionSourceFolder;
use App\Services\Ai\LLMClient;
use App\Services\Ai\Generation\ContentGenBatchLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Services\Ai\FolderEmbeddingScheduler;

class ScoreFolderCandidatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param array{should_create_folder:bool,confidence:float,context_type:string,primary_entity:string,folder_name:string,description:string} $proposed
     */
    public function __construct(public string $ingestionSourceId, public array $proposed) {}

    public function handle(LLMClient $llm): void
    {
        $logger = new ContentGenBatchLogger(storage_path('logs/ingestionSourceLogs'), true);
        $logger->startRun('ScoreFolderCandidatesJob:' . $this->ingestionSourceId, [
            'ingestion_source_id' => $this->ingestionSourceId,
        ]);

        $result = $this->run($llm);
        $logger->flush($result['status'] ?? 'completed', $result);
    }

    /**
     * Score candidates and deterministically decide create vs reuse, then attach.
     * @return array{status:string, decision?:string, folder_id?:string, created?:bool, reused?:bool, score?:float, candidates?:array<int,string>, matches?:array<int,array{folder_id:string,score:float}>}
     */
    public function run(LLMClient $llm): array
    {
        if (!Schema::hasTable('folders') || !Schema::hasTable('ingestion_source_folders')) {
            return ['status' => 'skipped_missing_tables'];
        }

        $src = IngestionSource::find($this->ingestionSourceId);
        if (!$src) {
            return ['status' => 'not_found'];
        }

        try {
            if (($src->origin ?? '') === 'eval_harness') {
                return ['status' => 'skipped_eval_harness'];
            }
        } catch (\Throwable) {}

        // Manual attachments always override AI attachments.
        try {
            if (method_exists($src, 'folders')) {
                $hasManual = $src->folders()
                    ->wherePivotNotNull('created_by')
                    ->exists();
                if ($hasManual) {
                    return ['status' => 'skipped_manual_folder_exists'];
                }
            }
        } catch (\Throwable) {
            // best-effort
        }

        if (($this->proposed['should_create_folder'] ?? false) !== true) {
            return ['status' => 'skipped_no_proposal'];
        }

        $contextType = trim((string) ($this->proposed['context_type'] ?? ''));
        $primaryEntity = trim((string) ($this->proposed['primary_entity'] ?? ''));
        $folderName = trim((string) ($this->proposed['folder_name'] ?? ''));
        $description = trim((string) ($this->proposed['description'] ?? ''));

        if ($contextType === '' || $folderName === '') {
            return ['status' => 'skipped_invalid_proposal'];
        }

        // Deterministic exact-match reuse (safe, avoids unnecessary duplicates).
        $exact = $this->findExactExistingFolder($src, $contextType, $primaryEntity, $folderName);
        if ($exact) {
            return $this->attachAndReturn($src, $exact, [
                'status' => 'attached',
                'decision' => 'reuse_exact_match',
                'folder_id' => (string) $exact->id,
                'created' => false,
                'reused' => true,
                'score' => 1.0,
                'candidates' => [(string) $exact->id],
                'matches' => [[ 'folder_id' => (string) $exact->id, 'score' => 1.0 ]],
            ]);
        }

        // Deterministic near-duplicate reuse: same semantic identity but trivial word drift.
        // Requirements: same context_type + same primary_entity + very high name similarity.
        $near = $this->findNearDuplicateFolder($src, $contextType, $primaryEntity, $folderName);
        if ($near) {
            $sim = $this->nameSimilarity($folderName, (string) $near->name);
            return $this->attachAndReturn($src, $near, [
                'status' => 'attached',
                'decision' => 'reuse_near_duplicate',
                'folder_id' => (string) $near->id,
                'created' => false,
                'reused' => true,
                'score' => $sim,
                'candidates' => [(string) $near->id],
                'matches' => [[ 'folder_id' => (string) $near->id, 'score' => $sim ]],
            ]);
        }

        $candidates = $this->fetchCandidates($src, $contextType, $primaryEntity, $folderName);
        $candidateIds = array_map(fn($f) => (string) $f->id, $candidates);

        try {
            Log::info('ai.folder_match.candidates', [
                'ingestion_source_id' => (string) $src->id,
                'org' => (string) $src->organization_id,
                'context_type' => $contextType,
                'candidate_ids' => $candidateIds,
            ]);
        } catch (\Throwable) {}

        if (empty($candidates)) {
            $folder = $this->createFolder($src, $contextType, $primaryEntity, $folderName, $description);
            return $this->attachAndReturn($src, $folder, [
                'status' => 'attached',
                'decision' => 'create_no_candidates',
                'folder_id' => (string) $folder->id,
                'created' => true,
                'reused' => false,
                'candidates' => [],
                'matches' => [],
            ]);
        }

        $system = <<<'PROMPT'
You are a system that scores similarity between a proposed folder and a small list of existing folders.

You MUST:
- never invent IDs
- never rename folders
- return scores between 0.0 and 1.0
- order matches by descending score

OUTPUT FORMAT (STRICT)
Return ONLY valid JSON:
{
  "matches": [
    { "folder_id": "uuid", "score": 0.86 }
  ]
}
PROMPT;

        $input = [
            'proposed' => [
                'folder_name' => $folderName,
                'context_type' => $contextType,
                'primary_entity' => $primaryEntity,
                'description' => $description,
            ],
            'candidates' => array_map(function (Folder $f) {
                $meta = is_array($f->metadata) ? $f->metadata : [];
                return [
                    'id' => (string) $f->id,
                    'name' => (string) $f->name,
                    'description' => (string) ($meta['description'] ?? ''),
                ];
            }, $candidates),
        ];

        try {
            $res = $llm->call('score_folder_candidates', $system, json_encode($input, JSON_UNESCAPED_UNICODE), 'score_folder_candidates_v1', [
                'temperature' => 0,
            ]);
            $data = is_array($res) ? $res : [];
        } catch (\Throwable $e) {
            try {
                Log::warning('ai.folder_match.llm_error', [
                    'ingestion_source_id' => (string) $src->id,
                    'org' => (string) $src->organization_id,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable) {}

            $folder = $this->createFolder($src, $contextType, $primaryEntity, $folderName, $description);
            return $this->attachAndReturn($src, $folder, [
                'status' => 'attached',
                'decision' => 'create_scoring_failed',
                'folder_id' => (string) $folder->id,
                'created' => true,
                'reused' => false,
                'candidates' => $candidateIds,
                'matches' => [],
            ]);
        }

        $matches = $this->normalizeMatches($data['matches'] ?? null, $candidateIds);
        $best = $matches[0]['score'] ?? 0.0;
        $bestId = $matches[0]['folder_id'] ?? '';

        try {
            Log::info('ai.folder_match.scores', [
                'ingestion_source_id' => (string) $src->id,
                'org' => (string) $src->organization_id,
                'matches' => $matches,
            ]);
        } catch (\Throwable) {}

        if ($bestId !== '' && $best >= 0.85) {
            $reuse = Folder::find($bestId);
            if ($reuse) {
                return $this->attachAndReturn($src, $reuse, [
                    'status' => 'attached',
                    'decision' => 'reuse_scored',
                    'folder_id' => (string) $reuse->id,
                    'created' => false,
                    'reused' => true,
                    'score' => $best,
                    'candidates' => $candidateIds,
                    'matches' => $matches,
                ]);
            }
        }

        $folder = $this->createFolder($src, $contextType, $primaryEntity, $folderName, $description);
        return $this->attachAndReturn($src, $folder, [
            'status' => 'attached',
            'decision' => 'create_below_threshold',
            'folder_id' => (string) $folder->id,
            'created' => true,
            'reused' => false,
            'score' => $best,
            'candidates' => $candidateIds,
            'matches' => $matches,
        ]);
    }

    private function findExactExistingFolder(IngestionSource $src, string $contextType, string $primaryEntity, string $folderName): ?Folder
    {
        try {
            /** @var Folder|null $f */
            $f = Folder::query()
                ->where('organization_id', (string) $src->organization_id)
                ->whereNull('parent_id')
                ->where('system_name', $folderName)
                ->where('metadata->context_type', $contextType)
                ->where('metadata->source', 'ai')
                ->first();
            if (!$f) {
                return null;
            }
            $meta = is_array($f->metadata) ? $f->metadata : [];
            $pe = (string) ($meta['primary_entity'] ?? '');
            if ($primaryEntity !== '' && $pe !== '' && $primaryEntity !== $pe) {
                return null;
            }
            if ($this->isPlatformNamedFolder((string) $f->name)) {
                return null;
            }
            return $f;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int,Folder>
     */
    private function fetchCandidates(IngestionSource $src, string $contextType, string $primaryEntity, string $folderName): array
    {
        try {
            $pool = Folder::query()
                ->where('organization_id', (string) $src->organization_id)
                ->whereNull('parent_id')
                ->where('metadata->context_type', $contextType)
                ->where('metadata->source', 'ai')
                ->limit(50)
                ->get();
        } catch (\Throwable) {
            $pool = collect();
        }

        $ranked = [];
        foreach ($pool as $f) {
            if ($this->isPlatformNamedFolder((string) ($f->system_name ?? $f->name))) {
                continue;
            }
            $meta = is_array($f->metadata) ? $f->metadata : [];
            $candPrimary = trim((string) ($meta['primary_entity'] ?? ''));

            $nameSim = $this->nameSimilarity($folderName, (string) ($f->system_name ?? $f->name));
            $primaryBoost = ($primaryEntity !== '' && $candPrimary !== '' && $primaryEntity === $candPrimary) ? 0.20 : 0.0;
            $rank = min(1.0, $nameSim + $primaryBoost);

            $ranked[] = ['rank' => $rank, 'folder' => $f];
        }

        usort($ranked, fn($a, $b) => $b['rank'] <=> $a['rank']);

        $out = [];
        foreach ($ranked as $row) {
            $out[] = $row['folder'];
            if (count($out) >= 7) {
                break;
            }
        }

        return $out;
    }

    private function createFolder(IngestionSource $src, string $contextType, string $primaryEntity, string $folderName, string $description): Folder
    {
        $folder = new Folder();
        $folder->organization_id = (string) $src->organization_id;
        $folder->parent_id = null;
        $folder->system_name = $folderName;
        $folder->system_named_at = now();
        $folder->display_name = null;
        $folder->metadata = [
            'context_type' => $contextType,
            'primary_entity' => $primaryEntity,
            'description' => $description,
            'confidence' => (float) ($this->proposed['confidence'] ?? 0.0),
            'source' => 'ai',
        ];
        $folder->created_by = null;
        $folder->save();
        return $folder;
    }

    private function findNearDuplicateFolder(IngestionSource $src, string $contextType, string $primaryEntity, string $folderName): ?Folder
    {
        $primaryEntity = trim($primaryEntity);
        $folderName = trim($folderName);
        if ($contextType === '' || $primaryEntity === '' || $folderName === '') {
            return null;
        }

        try {
            $pool = Folder::query()
                ->where('organization_id', (string) $src->organization_id)
                ->whereNull('parent_id')
                ->where('metadata->context_type', $contextType)
                ->where('metadata->source', 'ai')
                ->limit(75)
                ->get();
        } catch (\Throwable) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;

        foreach ($pool as $f) {
            if ($this->isPlatformNamedFolder((string) ($f->system_name ?? $f->name))) {
                continue;
            }

            $meta = is_array($f->metadata) ? $f->metadata : [];
            $candPrimary = trim((string) ($meta['primary_entity'] ?? ''));
            if ($candPrimary === '' || $candPrimary !== $primaryEntity) {
                continue;
            }

            $sim = $this->nameSimilarity($folderName, (string) ($f->system_name ?? $f->name));
            if ($sim >= 0.92 && $sim > $bestScore) {
                $best = $f;
                $bestScore = $sim;
            }
        }

        return $best;
    }

    private function attachAndReturn(IngestionSource $src, Folder $folder, array $payload): array
    {
        try {
            IngestionSourceFolder::firstOrCreate([
                'ingestion_source_id' => (string) $src->id,
                'folder_id' => (string) $folder->id,
            ], [
                'created_by' => null,
                'created_at' => now(),
            ]);
            try {
                app(FolderEmbeddingScheduler::class)->markStaleAndSchedule([(string) $folder->id], (string) $src->organization_id);
            } catch (\Throwable) {
                // ignore
            }
        } catch (\Throwable $e) {
            return array_merge($payload, ['status' => 'attach_failed', 'error' => $e->getMessage()]);
        }

        try {
            Log::info('ai.folder_match.decision', [
                'ingestion_source_id' => (string) $src->id,
                'org' => (string) $src->organization_id,
                'decision' => $payload['decision'] ?? null,
                'folder_id' => (string) $folder->id,
                'folder_name' => (string) ($folder->system_name ?? $folder->name),
                'created' => (bool) ($payload['created'] ?? false),
                'reused' => (bool) ($payload['reused'] ?? false),
                'score' => $payload['score'] ?? null,
            ]);
        } catch (\Throwable) {}

        return $payload;
    }

    /**
     * @param mixed $matches
     * @param array<int,string> $candidateIds
     * @return array<int,array{folder_id:string,score:float}>
     */
    private function normalizeMatches(mixed $matches, array $candidateIds): array
    {
        if (!is_array($matches)) {
            return [];
        }

        $allowed = array_fill_keys($candidateIds, true);
        $out = [];

        foreach ($matches as $m) {
            if (!is_array($m)) {
                continue;
            }
            $id = (string) ($m['folder_id'] ?? '');
            if ($id === '' || !isset($allowed[$id])) {
                continue;
            }
            $score = (float) ($m['score'] ?? 0.0);
            if ($score < 0.0) {
                $score = 0.0;
            }
            if ($score > 1.0) {
                $score = 1.0;
            }
            $out[] = ['folder_id' => $id, 'score' => $score];
        }

        usort($out, fn($a, $b) => $b['score'] <=> $a['score']);

        return $out;
    }

    private function isPlatformNamedFolder(string $name): bool
    {
        $n = mb_strtolower(trim($name));
        return in_array($n, [
            'instagram',
            'tiktok',
            'twitter',
            'linkedin',
            'youtube',
            'facebook',
            'threads',
            'pinterest',
            'reddit',
        ], true);
    }

    private function nameSimilarity(string $a, string $b): float
    {
        $a = mb_strtolower(trim($a));
        $b = mb_strtolower(trim($b));
        if ($a === '' || $b === '') {
            return 0.0;
        }
        similar_text($a, $b, $pct);
        return max(0.0, min(1.0, ((float) $pct) / 100.0));
    }
}
