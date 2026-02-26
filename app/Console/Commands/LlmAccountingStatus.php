<?php

namespace App\Console\Commands;

use App\Models\LlmCall;
use App\Services\Ai\LlmPricingTable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LlmAccountingStatus extends Command
{
    protected $signature = 'llm:accounting-status 
                            {--days=7 : Number of days to analyze}
                            {--detailed : Show detailed breakdown}';

    protected $description = 'Display LLM accounting system health and statistics';

    public function handle()
    {
        $days = (int) $this->option('days');
        $detailed = $this->option('detailed');

        $this->info("LLM Accounting System Status (Last {$days} days)");
        $this->line(str_repeat('=', 70));
        $this->newLine();

        // Overall Stats
        $this->showOverallStats($days);
        $this->newLine();

        // Completeness
        $this->showCompletenessStats($days);
        $this->newLine();

        // Cost Breakdown
        $this->showCostBreakdown($days);
        $this->newLine();

        // Model Usage
        $this->showModelUsage($days);
        $this->newLine();

        if ($detailed) {
            // Pipeline Stage Breakdown
            $this->showPipelineBreakdown($days);
            $this->newLine();

            // Request Type Breakdown
            $this->showRequestTypeBreakdown($days);
            $this->newLine();
        }

        // Health Checks
        $this->showHealthChecks($days);

        return Command::SUCCESS;
    }

    protected function showOverallStats(int $days): void
    {
        $stats = DB::table('llm_calls')
            ->where('created_at', '>', now()->subDays($days))
            ->selectRaw('
                COUNT(*) as total_calls,
                COUNT(DISTINCT organization_id) as unique_orgs,
                COALESCE(SUM(total_tokens), 0) as total_tokens,
                COALESCE(ROUND(SUM(cost_usd), 2), 0) as total_cost,
                COALESCE(ROUND(AVG(latency_ms), 0), 0) as avg_latency
            ')
            ->first();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Calls', number_format($stats->total_calls)],
                ['Unique Organizations', number_format($stats->unique_orgs)],
                ['Total Tokens', number_format($stats->total_tokens)],
                ['Total Cost', '$' . number_format($stats->total_cost, 2)],
                ['Avg Latency', number_format($stats->avg_latency) . ' ms'],
            ]
        );
    }

    protected function showCompletenessStats(int $days): void
    {
        $this->line('<fg=cyan>📊 Record Completeness</>');

        $stats = DB::table('llm_calls')
            ->where('created_at', '>', now()->subDays($days))
            ->selectRaw('
                COUNT(*) as total,
                COUNT(*) FILTER (WHERE record_complete = true) as complete,
                COUNT(*) FILTER (WHERE organization_id IS NULL) as missing_org,
                COUNT(*) FILTER (WHERE total_tokens IS NULL OR total_tokens = 0) as missing_tokens,
                COUNT(*) FILTER (WHERE cost_usd IS NULL) as missing_cost,
                COUNT(*) FILTER (WHERE pipeline_stage IS NULL) as missing_stage,
                COUNT(*) FILTER (WHERE request_type IS NULL) as missing_type
            ')
            ->first();

        $completePct = $stats->total > 0 ? round(100 * $stats->complete / $stats->total, 1) : 0;
        
        $this->table(
            ['Category', 'Count', 'Percentage'],
            [
                ['Complete Records', number_format($stats->complete), $completePct . '%'],
                ['Incomplete Records', number_format($stats->total - $stats->complete), (100 - $completePct) . '%'],
                ['Missing Org ID', number_format($stats->missing_org), round(100 * $stats->missing_org / $stats->total, 1) . '%'],
                ['Missing Tokens', number_format($stats->missing_tokens), round(100 * $stats->missing_tokens / $stats->total, 1) . '%'],
                ['Missing Cost', number_format($stats->missing_cost), round(100 * $stats->missing_cost / $stats->total, 1) . '%'],
                ['Missing Stage', number_format($stats->missing_stage), round(100 * $stats->missing_stage / $stats->total, 1) . '%'],
                ['Missing Type', number_format($stats->missing_type), round(100 * $stats->missing_type / $stats->total, 1) . '%'],
            ]
        );

        if ($completePct >= 95) {
            $this->info('✅ Excellent completeness!');
        } elseif ($completePct >= 80) {
            $this->warn('⚠️  Good completeness, room for improvement');
        } else {
            $this->error('❌ Low completeness - action needed');
        }
    }

    protected function showCostBreakdown(int $days): void
    {
        $this->line('<fg=cyan>💰 Cost Breakdown</>');

        $breakdown = DB::table('llm_calls')
            ->where('created_at', '>', now()->subDays($days))
            ->where('record_complete', true)
            ->selectRaw('
                COALESCE(pricing_source, \'unknown\') as source,
                COUNT(*) as calls,
                ROUND(SUM(cost_usd), 4) as total_cost
            ')
            ->groupBy('pricing_source')
            ->get();

        if ($breakdown->isEmpty()) {
            $this->warn('No cost data available');
            return;
        }

        $rows = $breakdown->map(fn($row) => [
            ucfirst($row->source),
            number_format($row->calls),
            '$' . number_format($row->total_cost, 4),
        ])->toArray();

        $this->table(['Source', 'Calls', 'Total Cost'], $rows);
    }

    protected function showModelUsage(int $days): void
    {
        $this->line('<fg=cyan>🤖 Top Models</>');

        $models = DB::table('llm_calls')
            ->where('created_at', '>', now()->subDays($days))
            ->selectRaw('
                model,
                COUNT(*) as calls,
                COALESCE(ROUND(SUM(cost_usd), 2), 0) as cost,
                COALESCE(ROUND(AVG(latency_ms), 0), 0) as avg_latency
            ')
            ->groupBy('model')
            ->orderByDesc('calls')
            ->limit(10)
            ->get();

        if ($models->isEmpty()) {
            $this->warn('No model usage data');
            return;
        }

        $rows = $models->map(fn($row) => [
            substr($row->model, 0, 40),
            number_format($row->calls),
            '$' . number_format($row->cost, 2),
            number_format($row->avg_latency) . ' ms',
        ])->toArray();

        $this->table(['Model', 'Calls', 'Cost', 'Avg Latency'], $rows);
    }

    protected function showPipelineBreakdown(int $days): void
    {
        $this->line('<fg=cyan>🔄 Pipeline Stage Breakdown</>');

        $stages = DB::table('llm_calls')
            ->where('created_at', '>', now()->subDays($days))
            ->whereNotNull('pipeline_stage')
            ->selectRaw('
                pipeline_stage,
                COUNT(*) as calls,
                COALESCE(ROUND(SUM(cost_usd), 2), 0) as cost
            ')
            ->groupBy('pipeline_stage')
            ->orderByDesc('cost')
            ->get();

        if ($stages->isEmpty()) {
            $this->warn('No pipeline stage data');
            return;
        }

        $rows = $stages->map(fn($row) => [
            ucfirst($row->pipeline_stage),
            number_format($row->calls),
            '$' . number_format($row->cost, 2),
        ])->toArray();

        $this->table(['Stage', 'Calls', 'Cost'], $rows);
    }

    protected function showRequestTypeBreakdown(int $days): void
    {
        $this->line('<fg=cyan>📋 Request Type Breakdown</>');

        $types = DB::table('llm_calls')
            ->where('created_at', '>', now()->subDays($days))
            ->whereNotNull('request_type')
            ->selectRaw('
                request_type,
                COUNT(*) as calls,
                COALESCE(ROUND(SUM(cost_usd), 2), 0) as cost
            ')
            ->groupBy('request_type')
            ->orderByDesc('cost')
            ->limit(10)
            ->get();

        if ($types->isEmpty()) {
            $this->warn('No request type data');
            return;
        }

        $rows = $types->map(fn($row) => [
            str_replace('_', ' ', ucfirst($row->request_type)),
            number_format($row->calls),
            '$' . number_format($row->cost, 2),
        ])->toArray();

        $this->table(['Type', 'Calls', 'Cost'], $rows);
    }

    protected function showHealthChecks(int $days): void
    {
        $this->line('<fg=cyan>🏥 Health Checks</>');

        $checks = [];

        // Check 1: Pricing table coverage
        $unknownModels = DB::table('llm_calls')
            ->where('created_at', '>', now()->subDays($days))
            ->whereNull('cost_usd')
            ->distinct()
            ->pluck('model')
            ->filter(fn($model) => !LlmPricingTable::hasModel($model))
            ->take(5);

        if ($unknownModels->isEmpty()) {
            $checks[] = ['✅', 'All models in pricing table', 'Pass'];
        } else {
            $checks[] = ['⚠️', 'Unknown models: ' . $unknownModels->join(', '), 'Warning'];
        }

        // Check 2: Error rate
        $errorRate = DB::table('llm_calls')
            ->where('created_at', '>', now()->subDays($days))
            ->selectRaw('
                COUNT(*) FILTER (WHERE status = \'failed\') * 100.0 / NULLIF(COUNT(*), 0) as error_pct
            ')
            ->value('error_pct') ?? 0;

        if ($errorRate < 5) {
            $checks[] = ['✅', 'Error rate: ' . round($errorRate, 1) . '%', 'Pass'];
        } elseif ($errorRate < 10) {
            $checks[] = ['⚠️', 'Error rate: ' . round($errorRate, 1) . '%', 'Warning'];
        } else {
            $checks[] = ['❌', 'Error rate: ' . round($errorRate, 1) . '%', 'Fail'];
        }

        // Check 3: Recent activity
        $recentCalls = DB::table('llm_calls')
            ->where('created_at', '>', now()->subHour())
            ->count();

        if ($recentCalls > 0) {
            $checks[] = ['✅', "Recent calls: {$recentCalls} in last hour", 'Pass'];
        } else {
            $checks[] = ['⚠️', 'No calls in last hour', 'Warning'];
        }

        $this->table(['Status', 'Check', 'Result'], $checks);
    }
}
