<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Services\Ai\Research\ResearchExecutor;
use App\Services\Ai\Research\DTO\ResearchOptions;
use App\Services\Ai\Research\Formatters\CliResearchFormatter;
use App\Enums\ResearchStage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class AiResearchCommand extends Command
{
    protected $signature = 'ai:research:ask {question}
        {--stage=deep_research : trend_discovery|deep_research|angle_hooks}
        {--industry= : Industry/topic seed for trend discovery}
        {--platforms= : Comma list of platforms for trend discovery}
        {--trend-limit=10 : Trend candidates to return}
        {--hooks=5 : Hook count for angle_hooks stage}
        {--limit=40 : Max retrieved items}
        {--include-kb=false : Include knowledge base chunks}
        {--sources= : Comma list (post,research_fragment,ti)}
        {--dump= : raw|clusters|snapshot|report}
        {--json : Output raw JSON}
        {--trace : Verbose execution trace}';

    protected $aliases = [
        'ai:research',
    ];

    protected $description = 'Run a Research Mode query against Creative Intelligence data';

    public function handle(ResearchExecutor $executor, CliResearchFormatter $formatter): int
    {
        $question = trim((string) $this->argument('question'));
        if ($question === '') {
            $this->error('Question is required.');
            return 1;
        }

        $stageRaw = trim((string) $this->option('stage'));
        $stageRaw = $stageRaw !== '' ? $stageRaw : 'deep_research';
        $stage = ResearchStage::fromString($stageRaw);
        if ($stage === null) {
            $this->error('Invalid --stage. Use trend_discovery, deep_research, or angle_hooks.');
            return 1;
        }

        $limit = (int) $this->option('limit');
        $limit = max(1, min(100, $limit));

        $includeKb = $this->parseBoolOption($this->option('include-kb'));
        $sourcesRaw = trim((string) $this->option('sources'));
        [$mediaTypes, $tiRequested, $invalidSources] = $this->parseSources($sourcesRaw);

        if ($tiRequested) {
            $this->warn('Source "ti" is not supported in research retrieval yet; ignoring.');
        }
        if (!empty($invalidSources)) {
            $this->warn('Ignoring unknown sources: ' . implode(', ', $invalidSources));
        }

        $dump = trim((string) $this->option('dump'));
        $allowedDumps = ['raw', 'clusters', 'snapshot', 'report', ''];
        if (!in_array($dump, $allowedDumps, true)) {
            $this->error('Invalid --dump. Use raw, clusters, snapshot, or report.');
            return 1;
        }

        if ($stage !== ResearchStage::DEEP_RESEARCH && !in_array($dump, ['', 'report'], true)) {
            $this->warn('Dump options raw/clusters/snapshot only apply to deep_research; using report output.');
            $dump = 'report';
        }

        $asJson = (bool) $this->option('json');
        $trace = (bool) $this->option('trace');

        [$orgId, $userId] = $this->resolveOrgUser();
        if ($orgId === '' || $userId === '') {
            $this->error('No organization/user found. Provide org/user data in the database.');
            return 1;
        }

        // Build research options
        $optionsArray = [
            'retrieval_limit' => $limit,
            'include_kb' => $includeKb,
            'hooks_count' => (int) $this->option('hooks'),
            'trend_limit' => (int) $this->option('trend-limit'),
            'return_debug' => ($trace || $dump !== '' || $asJson),
            'trace' => $trace,
        ];

        if (!empty($mediaTypes)) {
            $optionsArray['research_media_types'] = $mediaTypes;
        }

        $industry = trim((string) $this->option('industry'));
        if ($industry !== '') {
            $optionsArray['research_industry'] = $industry;
        }

        $platformsRaw = trim((string) $this->option('platforms'));
        if ($platformsRaw !== '') {
            $platforms = array_values(array_filter(array_map('trim', explode(',', $platformsRaw)), fn($v) => $v !== ''));
            $optionsArray['trend_platforms'] = $platforms;
        }

        $options = ResearchOptions::fromArray($orgId, $userId, $optionsArray);

        // Execute research
        $startedAt = microtime(true);
        $result = $executor->run($question, $stage, $options);
        $totalMs = (int) round((microtime(true) - $startedAt) * 1000);

        // Write snapshot content if available
        if ($result->snapshotId) {
            $this->writeSnapshotContent($result->snapshotId, json_encode($result->toReport()));
        }

        // Handle dump modes
        if ($dump !== '') {
            return $this->renderDump($dump, $result, $totalMs, $asJson, $trace, $formatter);
        }

        // Standard output
        if ($asJson) {
            $this->line(json_encode($result->toReport(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            if ($trace) {
                $this->line($formatter->formatTrace($result, $totalMs));
            }
            return 0;
        }

        $this->line($formatter->format($result));
        if ($trace) {
            $this->line($formatter->formatTrace($result, $totalMs));
        }

        return 0;
    }

    private function parseSources(string $sourcesRaw): array
    {
        if ($sourcesRaw === '') {
            return [[], false, []];
        }

        $mediaTypes = [];
        $invalid = [];
        $tiRequested = false;
        $parts = array_values(array_filter(array_map('trim', explode(',', $sourcesRaw)), fn($v) => $v !== ''));

        foreach ($parts as $part) {
            $key = strtolower($part);
            if ($key === 'post') {
                $mediaTypes[] = 'post';
            } elseif ($key === 'research_fragment' || $key === 'research-fragment' || $key === 'fragment') {
                $mediaTypes[] = 'research_fragment';
            } elseif ($key === 'ti') {
                $tiRequested = true;
            } else {
                $invalid[] = $part;
            }
        }

        $mediaTypes = array_values(array_unique($mediaTypes));
        return [$mediaTypes, $tiRequested, $invalid];
    }

    private function parseBoolOption($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return false;
        }
        return in_array($raw, ['1', 'true', 'yes', 'y', 'on'], true);
    }

    private function resolveOrgUser(): array
    {
        $membership = OrganizationMember::query()->orderByDesc('created_at')->first();
        if ($membership) {
            return [(string) $membership->organization_id, (string) $membership->user_id];
        }

        $org = Organization::query()->orderBy('created_at')->first();
        $user = User::query()->orderBy('created_at')->first();

        return [$org ? (string) $org->id : '', $user ? (string) $user->id : ''];
    }

    private function renderDump(
        string $dump,
        \App\Services\Ai\Research\DTO\ResearchResult $result,
        int $totalMs,
        bool $asJson,
        bool $trace,
        CliResearchFormatter $formatter
    ): int {
        $payload = [];

        if ($dump === 'raw') {
            $items = (array) ($result->debug['items'] ?? []);
            $payload = [
                'question' => $result->question,
                'items' => array_map(function ($item) {
                    return [
                        'id' => (string) ($item['id'] ?? ''),
                        'source' => (string) ($item['source'] ?? ''),
                        'platform' => (string) ($item['platform'] ?? ''),
                        'media_type' => (string) ($item['media_type'] ?? ''),
                        'similarity' => isset($item['similarity']) ? (float) $item['similarity'] : null,
                        'engagement_score' => isset($item['engagement_score']) ? (float) $item['engagement_score'] : null,
                        'match_type' => (string) ($item['match_type'] ?? ''),
                        'text_preview' => mb_substr((string) ($item['text'] ?? ''), 0, 180),
                    ];
                }, $items),
            ];
        } elseif ($dump === 'clusters') {
            $payload = [
                'question' => $result->question,
                'clusters' => (array) ($result->debug['clusters'] ?? []),
            ];
        } elseif ($dump === 'snapshot') {
            $payload = [
                'question' => $result->question,
                'snapshot_id' => $result->snapshotId ?? '',
                'run_id' => (string) ($result->metadata['run_id'] ?? ''),
                'mode' => 'research',
            ];
        } elseif ($dump === 'report') {
            $payload = $result->toReport();
        }

        if ($asJson) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            if ($trace) {
                $this->line($formatter->formatTrace($result, $totalMs));
            }
            return 0;
        }

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if ($trace) {
            $this->line($formatter->formatTrace($result, $totalMs));
        }
        return 0;
    }

    private function writeSnapshotContent(string $snapshotId, string $content): void
    {
        if ($content === '' || $snapshotId === '') {
            return;
        }

        $fileId = $snapshotId !== '' ? $snapshotId : now()->format('Ymd_His');
        $dir = 'ai-research';
        $path = $dir . '/snapshot-' . $fileId . '.json';

        try {
            Storage::disk('local')->put($path, $content);
            $this->line('Snapshot content written: ' . storage_path('app/' . $path));
        } catch (\Throwable $e) {
            $this->warn('Failed to write snapshot content: ' . $e->getMessage());
        }
    }
}
