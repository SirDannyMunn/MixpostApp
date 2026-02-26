<?php

namespace App\Jobs;

use App\Models\IngestionSource;
use App\Services\Ai\LLMClient;
use App\Services\Ai\Generation\ContentGenBatchLogger;
use App\Services\Ingestion\IngestionContentResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class InferContextFolderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $ingestionSourceId) {}

    public function handle(LLMClient $llm, IngestionContentResolver $resolver): void
    {
        $logger = new ContentGenBatchLogger(storage_path('logs/ingestionSourceLogs'), true);
        $logger->startRun('InferContextFolderJob:' . $this->ingestionSourceId, [
            'ingestion_source_id' => $this->ingestionSourceId,
        ]);

        $result = $this->run($llm, $resolver);
        $logger->flush($result['should_create_folder'] ? 'proposed' : 'skipped', [
            'inference' => $result,
        ]);

        if (($result['should_create_folder'] ?? false) === true) {
            try {
                dispatch(new \App\Jobs\ScoreFolderCandidatesJob(
                    ingestionSourceId: $this->ingestionSourceId,
                    proposed: $result,
                ));
            } catch (\Throwable) {
                // non-fatal
            }
        }
    }

    /**
     * Execute inference only.
     *
     * Strict output contract:
     * {
     *   should_create_folder: boolean,
     *   confidence: number,
     *   context_type: string,
     *   primary_entity: string,
     *   folder_name: string,
     *   description: string
     * }
     */
    public function run(LLMClient $llm, IngestionContentResolver $resolver): array
    {
        $src = IngestionSource::find($this->ingestionSourceId);
        if (!$src) {
            return $this->declined();
        }

        // Avoid polluting deterministic eval runs
        try {
            if (($src->origin ?? '') === 'eval_harness') {
                return $this->declined();
            }
        } catch (\Throwable) {}

        // Manual attachments always win: if a user already attached any folder, do nothing.
        try {
            if (method_exists($src, 'folders')) {
                $hasManual = $src->folders()
                    ->wherePivotNotNull('created_by')
                    ->exists();
                if ($hasManual) {
                    return $this->declined();
                }
            }
        } catch (\Throwable) {
            // If relationship not available, continue best-effort
        }

        $raw = null;
        try {
            $raw = $resolver->resolve($src);
        } catch (\Throwable) {
            $raw = null;
        }

        $raw = trim((string) $raw);
        if ($raw === '') {
            return $this->declined();
        }

        $system = <<<'PROMPT'
You are a system that decides whether a piece of content belongs to a
REUSABLE SEMANTIC CONTEXT (a folder), and if so, proposes a STABLE folder name.

Folders are NOT collections, NOT platforms, and NOT sources.
Folders represent ONGOING NARRATIVES, CAMPAIGNS, or REUSABLE TOPICS.

Your job is to:
1) Decide whether a folder should be created
2) If yes, propose a folder name that MULTIPLE RELATED CONTENT ITEMS
   SHOULD SHARE over time

If no clear reusable context exists, DO NOT create a folder.

────────────────────────────────────────
WHAT A FOLDER IS
────────────────────────────────────────
A folder represents ONE of the following:
- an ongoing campaign (e.g. fundraiser, launch)
- a recurring narrative (e.g. product updates, personal journey)
- a reusable research theme or topic (e.g. AI content strategy, social media hooks)
- a content series (e.g. weekly tips, educational series)

A folder MUST be reusable across multiple future pieces of content.

────────────────────────────────────────
WHAT A FOLDER IS NOT
────────────────────────────────────────
DO NOT create folders for:
- platforms (TikTok, Twitter, Instagram, YouTube, LinkedIn)
- content sources or origins
- one-off posts with no broader theme
- generic advice with no clear unifying topic
- vague buckets like “Ideas”, “Thoughts”, “Posts”

If the only obvious grouping is the PLATFORM → DO NOT CREATE A FOLDER.

────────────────────────────────────────
NAMING RULES (CRITICAL)
────────────────────────────────────────
Folder names MUST:
- describe WHAT the content is about, not WHERE it came from
- be stable across similar content
- be reusable for future related content
- be short (2–6 words)
- be human-readable
- be singular
- NOT include platform names
- use the most DIRECT, CANONICAL form (avoid unnecessary articles/prepositions)

CONSISTENCY REQUIREMENT:
Multiple pieces about the SAME campaign/topic MUST produce IDENTICAL names.
Use the PRIMARY NAME or most common reference.
Avoid minor variations like:
  ❌ "Every Step for Eleanor" vs "Every Step of Eleanor" → ✅ "Eleanor Fundraiser"
  ❌ "Banham Marsden March Event" vs "Marsden March" → ✅ "Banham Marsden March"
  ❌ "The Velocity Launch" vs "Velocity Extension Launch" → ✅ "Velocity Extension Launch"

Good folder names:
- “Eleanor Cancer Fundraiser”
- “Velocity Extension Launch”
- “AI Content Strategy”
- “Social Media Hooks”
- “LaundryOS Customer Education”- "Banham Marsden March"
Bad folder names:
- “TikTok”
- “Instagram Posts”
- “Twitter Content”
- “Social Media”
- “Random Thoughts”- "Every Step for Eleanor" (too wordy; use "Eleanor Fundraiser")
- "The Eleanor Campaign" (unnecessary article)
If you cannot produce a GOOD name that would still make sense
after 10 similar pieces of content → do NOT create a folder.

────────────────────────────────────────
CONTEXT TYPES
────────────────────────────────────────
Choose ONE from:
- fundraiser
- launch
- case_study
- awareness
- research_theme
- content_series
- event
- personal_campaign

Do NOT invent new types.
Do NOT use platform.

PRIMARY ENTITY (CLARIFICATION)
primary_entity must represent the SUBJECT the folder is about (campaign, organisation, named initiative),
not the author or organiser unless they are explicitly the campaign itself.

────────────────────────────────────────
REUSE REQUIREMENT
────────────────────────────────────────
If two pieces of content are about the SAME underlying topic,
they MUST produce the SAME folder_name.

────────────────────────────────────────
OUTPUT FORMAT (STRICT)
────────────────────────────────────────
Return ONLY valid JSON with EXACTLY these keys:
{
  "should_create_folder": boolean,
  "confidence": number,
  "context_type": string,
  "primary_entity": string,
  "folder_name": string,
  "description": string
}
PROMPT;

        $user = json_encode([
            'ingestion_source_id' => (string) $src->id,
            'organization_id' => (string) $src->organization_id,
            'user_id' => (string) $src->user_id,
            'source_type' => (string) ($src->source_type ?? ''),
            'title' => (string) ($src->title ?? ''),
            'raw_url' => (string) ($src->raw_url ?? ''),
            'raw_text' => mb_substr($raw, 0, 20000),
            'metadata' => is_array($src->metadata) ? $src->metadata : null,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $res = $llm->call('infer_context_folder', $system, $user, 'infer_context_folder_v1', [
                'temperature' => 0,
            ]);
            $data = is_array($res) ? $res : [];
        } catch (\Throwable $e) {
            try {
                Log::warning('ai.folder_infer.llm_error', [
                    'ingestion_source_id' => (string) $src->id,
                    'org' => (string) $src->organization_id,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable) {}

            return $this->declined();
        }

        $should = (bool) ($data['should_create_folder'] ?? false);
        $confidence = (float) ($data['confidence'] ?? 0.0);
        $folderName = trim((string) ($data['folder_name'] ?? ''));
        $contextType = trim((string) ($data['context_type'] ?? ''));
        $primaryEntity = trim((string) ($data['primary_entity'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));

        $result = $this->normalizeContract([
            'should_create_folder' => $should,
            'confidence' => $confidence,
            'context_type' => $contextType,
            'primary_entity' => $primaryEntity,
            'folder_name' => $folderName,
            'description' => $description,
        ]);

        try {
            Log::info('ai.folder_infer.output', [
                'ingestion_source_id' => (string) $src->id,
                'org' => (string) $src->organization_id,
                'output' => $result,
            ]);
        } catch (\Throwable) {}

        return $result;
    }

    private function declined(): array
    {
        return [
            'should_create_folder' => false,
            'confidence' => 0.0,
            'context_type' => '',
            'primary_entity' => '',
            'folder_name' => '',
            'description' => '',
        ];
    }

    private function normalizeContract(array $data): array
    {
        $allowedTypes = [
            'fundraiser',
            'launch',
            'case_study',
            'awareness',
            'research_theme',
            'content_series',
            'event',
            'personal_campaign',
        ];

        $should = (bool) ($data['should_create_folder'] ?? false);
        $confidence = (float) ($data['confidence'] ?? 0.0);
        $contextType = trim((string) ($data['context_type'] ?? ''));
        $primaryEntity = trim((string) ($data['primary_entity'] ?? ''));
        $folderName = trim((string) ($data['folder_name'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));

        $folderName = mb_substr($folderName, 0, 120);
        $contextType = mb_substr($contextType, 0, 60);
        $primaryEntity = mb_substr($primaryEntity, 0, 120);
        $description = mb_substr($description, 0, 500);

        if ($should !== true) {
            return $this->declined();
        }

        if ($confidence < 0.70) {
            return $this->declined();
        }

        if ($folderName === '' || $contextType === '' || !in_array($contextType, $allowedTypes, true)) {
            return $this->declined();
        }

        // Safety: if the model violates platform exclusion, decline rather than create.
        if (preg_match('/\b(instagram|tiktok|twitter|linkedin|youtube|facebook|threads|pinterest|reddit)\b/i', $folderName)) {
            return $this->declined();
        }

        return [
            'should_create_folder' => true,
            'confidence' => $confidence,
            'context_type' => $contextType,
            'primary_entity' => $primaryEntity,
            'folder_name' => $folderName,
            'description' => $description,
        ];
    }
}
