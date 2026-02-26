<?php

namespace App\Console\Commands\Ai;

use App\Enums\ResearchStage;
use App\Services\Ai\Research\DTO\ResearchOptions;
use App\Services\Ai\Research\ResearchExecutor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Verification command for canonical Social Watcher integration
 * 
 * Tests research mode with canonical reader and verifies no legacy tables were accessed.
 */
class VerifyCanonicalResearchCommand extends Command
{
    protected $signature = 'ai:research:verify-canonical
                            {--stage=deep_research : Research stage to test}
                            {--query= : Query to test with}
                            {--org= : Organization ID}
                            {--user= : User ID}';

    protected $description = 'Verify canonical Social Watcher integration for research mode';

    public function handle(ResearchExecutor $executor): int
    {
        $stage = $this->option('stage') ?? 'deep_research';
        $query = $this->option('query') ?? 'AI content marketing strategies';
        $orgId = $this->option('org');
        $userId = $this->option('user');

        // Ensure canonical reader is enabled
        $readerMode = config('research.social_watcher_reader');
        if ($readerMode !== 'canonical') {
            $this->error("Research reader mode is set to '{$readerMode}', not 'canonical'");
            $this->info("Set RESEARCH_SOCIAL_WATCHER_READER=canonical in .env to test canonical mode");
            return 1;
        }

        if (!$orgId || !$userId) {
            $this->error('Both --org and --user are required');
            return 1;
        }

        $this->info("Testing research mode with canonical Social Watcher reader");
        $this->info("Stage: {$stage}");
        $this->info("Query: {$query}");
        $this->line('');

        // Enable query logging to detect legacy table access
        DB::enableQueryLog();

        try {
            $researchStage = match ($stage) {
                'deep_research' => ResearchStage::DEEP_RESEARCH,
                'angle_hooks' => ResearchStage::ANGLE_HOOKS,
                'trend_discovery' => ResearchStage::TREND_DISCOVERY,
                'saturation_opportunity' => ResearchStage::SATURATION_OPPORTUNITY,
                default => throw new \InvalidArgumentException("Unknown stage: {$stage}"),
            };

            $options = ResearchOptions::fromArray($orgId, $userId, [
                'limit' => 20,
                'return_debug' => true,
                'platforms' => ['x', 'linkedin'],
            ]);

            $this->info('Executing research...');
            $result = $executor->run($query, $researchStage, $options);

            $this->newLine();
            $this->info('✓ Research execution completed');
            $this->info("Snapshot ID: {$result->snapshotId}");

            // Analyze queries
            $queries = DB::getQueryLog();
            $this->newLine();
            $this->info("Total queries executed: " . count($queries));

            // Check for legacy table access
            $legacyTables = [
                'sw_normalized_content',
                'sw_normalized_content_fragments',
                'sw_creative_units',
                'sw_creative_clusters',
                'sw_creative_cluster_items',
            ];

            $legacyAccess = [];
            foreach ($queries as $query) {
                $sql = strtolower($query['query']);
                foreach ($legacyTables as $table) {
                    if (str_contains($sql, $table)) {
                        $legacyAccess[] = [
                            'table' => $table,
                            'query' => $query['query'],
                        ];
                    }
                }
            }

            if (!empty($legacyAccess)) {
                $this->newLine();
                $this->error('✗ Legacy Social Watcher tables were accessed:');
                foreach ($legacyAccess as $access) {
                    $this->line("  Table: {$access['table']}");
                    $this->line("  Query: " . substr($access['query'], 0, 100) . '...');
                    $this->newLine();
                }
                return 1;
            }

            // Check for canonical table access
            $canonicalTables = [
                'sw_content_nodes',
                'sw_content_fragments',
                'sw_content_annotations',
                'sw_embeddings',
                'sw_annotation_clusters',
            ];

            $canonicalAccess = [];
            foreach ($queries as $query) {
                $sql = strtolower($query['query']);
                foreach ($canonicalTables as $table) {
                    if (str_contains($sql, $table)) {
                        $canonicalAccess[] = $table;
                    }
                }
            }

            if (!empty($canonicalAccess)) {
                $this->newLine();
                $this->info('✓ Canonical Social Watcher tables accessed:');
                foreach (array_unique($canonicalAccess) as $table) {
                    $this->line("  - {$table}");
                }
            } else {
                $this->newLine();
                $this->warn('⚠ No canonical tables were accessed (might indicate empty data or fallback)');
            }

            // Display result summary
            $this->newLine();
            $this->info('Result summary:');
            $this->line("  Stage: {$result->stage->value}");
            $this->line("  Question: {$result->question}");

            $debug = $result->debug;
            if (!empty($debug)) {
                $counts = $debug['counts'] ?? [];
                if (!empty($counts)) {
                    $this->line("  Items: " . ($counts['items'] ?? 0));
                    $this->line("  Clusters: " . ($counts['clusters'] ?? 0));
                }
            }

            $this->newLine();
            $this->info('✓ Verification complete - canonical integration working correctly');

            return 0;

        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('✗ Research execution failed:');
            $this->error($e->getMessage());
            $this->line($e->getTraceAsString());
            return 1;
        } finally {
            DB::disableQueryLog();
        }
    }
}
