<?php

namespace App\Services\Ai\Research;

use App\Services\Ai\Research\Sources\SocialWatcherResearchGateway;
use App\Services\Ai\Research\DTO\ResearchOptions;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TrendDiscoveryService
{
    public function __construct(
        protected SocialWatcherResearchGateway $swGateway,
    ) {}
    /**
     * @return array{trends:array<int,array<string,mixed>>,meta:array<string,mixed>}
     */
    public function discover(
        string $organizationId,
        string $query,
        string $industry = '',
        array $platforms = [],
        array $options = []
    ): array {
        $limit = (int) ($options['limit'] ?? 10);
        $limit = max(1, min(20, $limit));
        $recentDays = (int) ($options['recent_days'] ?? 7);
        $recentDays = max(1, min(14, $recentDays));
        $daysBack = (int) ($options['days_back'] ?? 30);
        $daysBack = max($recentDays + 1, min(90, $daysBack));
        $minRecent = (int) ($options['min_recent'] ?? 3);
        $minRecent = max(1, min(20, $minRecent));

        // Try canonical path first
        $useCanonical = config('research.social_watcher_reader', 'legacy') === 'canonical';
        
        if ($useCanonical) {
            try {
                $researchOpts = ResearchOptions::fromArray($organizationId, '', array_merge($options, [
                    'platforms' => $platforms,
                    'trend_limit' => $limit * 3, // Get more for processing
                    'trend_recent_days' => $recentDays,
                ]));
                
                $recentItems = $this->swGateway->getRecentTrending($organizationId, $researchOpts);
                
                if (!empty($recentItems)) {
                    return $this->analyzeCanonicalTrends($recentItems, $query, $industry, $options);
                }
            } catch (\Throwable $e) {
                \Log::warning('TrendDiscoveryService: canonical path failed, falling back to legacy', [
                    'error' => $e->getMessage(),
                ]);
            }
            
            // If canonical mode is strict, don't fall back to legacy
            if ($useCanonical) {
                return [
                    'trends' => [],
                    'meta' => ['mode' => 'canonical', 'message' => 'No canonical data available'],
                ];
            }
        }

        // Legacy path (only when not in canonical mode)
        $seed = trim($industry) !== '' ? $industry : $query;
        $keywords = $this->extractKeywords($seed, 8);
        if (empty($keywords)) {
            $keywords = $this->extractKeywords($query, 8);
        }

        $prefix = config('social-watcher.table_prefix', 'sw_');
        $table = $prefix . 'normalized_content';
        if (!Schema::hasTable($table)) {
            return [
                'trends' => [],
                'meta' => ['error' => 'missing_table', 'table' => $table],
            ];
        }

        $hasOrg = Schema::hasColumn($table, 'organization_id');
        $hasPublished = Schema::hasColumn($table, 'published_at');
        $hasCreated = Schema::hasColumn($table, 'created_at');
        $hasPlatform = Schema::hasColumn($table, 'platform');

        $cutoff = now()->subDays($daysBack);
        $q = DB::table($table)
            ->select([
                'id',
                'text',
                'title',
                'published_at',
                'created_at',
                'platform',
                'engagement_score',
            ])
            ->when($hasOrg, function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })
            ->when($hasPlatform && !empty($platforms), function ($q) use ($platforms) {
                $q->whereIn('platform', array_values(array_filter(array_map('strval', $platforms))));
            });

        if ($hasPublished) {
            $q->where('published_at', '>=', $cutoff);
        } elseif ($hasCreated) {
            $q->where('created_at', '>=', $cutoff);
        }

        if (!empty($keywords)) {
            $q->where(function ($w) use ($keywords) {
                foreach ($keywords as $kw) {
                    $like = '%' . $kw . '%';
                    $w->orWhere('text', 'like', $like)->orWhere('title', 'like', $like);
                }
            });
        }

        $rows = $q->orderByDesc($hasPublished ? 'published_at' : 'created_at')
            ->limit(2500)
            ->get();

        $stats = [];
        $totalItems = 0;
        $baselineDays = max(1, $daysBack - $recentDays);
        foreach ($rows as $row) {
            $publishedAt = $this->resolveTimestamp($row);
            if (!$publishedAt) {
                continue;
            }
            $age = now()->diffInDays($publishedAt);
            if ($age > $daysBack) {
                continue;
            }

            $bucket = $age <= $recentDays ? 'recent' : 'baseline';
            $text = trim((string) ($row->title ?? '') . ' ' . (string) ($row->text ?? ''));
            if ($text === '') {
                continue;
            }
            $totalItems++;
            $tokens = $this->extractKeywords($text, 6);
            foreach ($tokens as $token) {
                $stats[$token] = $stats[$token] ?? [
                    'recent' => 0,
                    'baseline' => 0,
                    'engagement_sum' => 0.0,
                    'total' => 0,
                ];
                $stats[$token][$bucket]++;
                $stats[$token]['engagement_sum'] += (float) ($row->engagement_score ?? 0.0);
                $stats[$token]['total']++;
            }
        }

        $candidates = [];
        foreach ($stats as $token => $data) {
            $recent = (int) $data['recent'];
            $baseline = (int) $data['baseline'];
            if ($recent < $minRecent) {
                continue;
            }
            $expected = $baseline * ($recentDays / $baselineDays);
            $velocity = $expected > 0 ? ($recent / $expected) : $recent;
            $avgEngagement = $data['total'] > 0 ? ($data['engagement_sum'] / $data['total']) : 0.0;
            $confidence = min(1.0, (log(1 + $recent) / log(1 + 20)) * 0.6 + min(1.0, $velocity / 4) * 0.4);

            $candidates[] = [
                'trend_label' => $token,
                'why_trending' => sprintf(
                    'Recent posts (%d in last %d days) outpaced baseline (%d in prior %d days).',
                    $recent,
                    $recentDays,
                    $baseline,
                    $baselineDays
                ),
                'evidence' => [
                    'recent_posts' => $recent,
                    'baseline_posts' => $baseline,
                    'velocity_ratio' => round($velocity, 2),
                    'avg_engagement' => round($avgEngagement, 2),
                ],
                'confidence' => round($confidence, 2),
            ];
        }

        usort($candidates, function ($a, $b) {
            $va = (float) ($a['evidence']['velocity_ratio'] ?? 0.0);
            $vb = (float) ($b['evidence']['velocity_ratio'] ?? 0.0);
            if ($va === $vb) {
                $ra = (int) ($a['evidence']['recent_posts'] ?? 0);
                $rb = (int) ($b['evidence']['recent_posts'] ?? 0);
                return $rb <=> $ra;
            }
            return $vb <=> $va;
        });

        return [
            'trends' => array_slice($candidates, 0, $limit),
            'meta' => [
                'seed' => $seed,
                'items_considered' => $totalItems,
                'days_back' => $daysBack,
                'recent_days' => $recentDays,
                'platforms' => $platforms,
            ],
        ];
    }

    private function resolveTimestamp(object $row): ?Carbon
    {
        try {
            if (!empty($row->published_at)) {
                return Carbon::parse($row->published_at);
            }
            if (!empty($row->created_at)) {
                return Carbon::parse($row->created_at);
            }
        } catch (\Throwable) {
            return null;
        }
        return null;
    }

    private function extractKeywords(string $text, int $max = 6): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace('~https?://\S+~i', ' ', $text);
        $text = preg_replace('/[^a-z0-9\s]+/i', ' ', $text);
        $parts = preg_split('/\s+/', $text) ?: [];

        $stop = [
            'the','and','for','with','that','this','from','your','you','our','are','was','were','will','can',
            'about','into','over','under','after','before','just','have','has','had','not','but','they','them',
            'their','its','what','when','where','why','how','who','which','also','use','using','make','makes',
            'made','like','more','most','less','much','many','too','very','per','via','set','get','new','best',
            'top','today','trend','trending','industry','market','latest','news',
        ];
        $stop = array_flip($stop);

        $counts = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '' || mb_strlen($p) < 3) { continue; }
            if (isset($stop[$p])) { continue; }
            $counts[$p] = ($counts[$p] ?? 0) + 1;
        }
        if (empty($counts)) {
            return [];
        }
        arsort($counts);
        return array_slice(array_keys($counts), 0, $max);
    }

    /**
     * Analyze trends from canonical Social Watcher evidence items
     * 
     * @param array $evidenceItems Array of SocialEvidenceItem DTOs
     * @param string $query
     * @param string $industry
     * @param array $options
     * @return array
     */
    private function analyzeCanonicalTrends(array $evidenceItems, string $query, string $industry, array $options): array
    {
        $limit = (int) ($options['limit'] ?? 10);
        $recentDays = (int) ($options['recent_days'] ?? 7);
        $daysBack = (int) ($options['days_back'] ?? 30);
        $minRecent = (int) ($options['min_recent'] ?? 3);
        
        $stats = [];
        $totalItems = count($evidenceItems);
        $baselineDays = max(1, $daysBack - $recentDays);
        
        foreach ($evidenceItems as $item) {
            $publishedAt = $item->publishedAt;
            if (!$publishedAt) {
                continue;
            }
            
            $age = now()->diffInDays($publishedAt);
            if ($age > $daysBack) {
                continue;
            }
            
            $bucket = $age <= $recentDays ? 'recent' : 'baseline';
            $text = $item->text;
            $tokens = $this->extractKeywords($text, 6);
            
            $engagement = $item->metrics['engagement_score'] ?? 0.0;
            
            foreach ($tokens as $token) {
                $stats[$token] = $stats[$token] ?? [
                    'recent' => 0,
                    'baseline' => 0,
                    'engagement_sum' => 0.0,
                    'total' => 0,
                ];
                $stats[$token][$bucket]++;
                $stats[$token]['engagement_sum'] += (float) $engagement;
                $stats[$token]['total']++;
            }
        }
        
        $candidates = [];
        foreach ($stats as $token => $data) {
            $recent = (int) $data['recent'];
            $baseline = (int) $data['baseline'];
            if ($recent < $minRecent) {
                continue;
            }
            $expected = $baseline * ($recentDays / $baselineDays);
            $velocity = $expected > 0 ? ($recent / $expected) : $recent;
            $avgEngagement = $data['total'] > 0 ? ($data['engagement_sum'] / $data['total']) : 0.0;
            $confidence = min(1.0, (log(1 + $recent) / log(1 + 20)) * 0.6 + min(1.0, $velocity / 4) * 0.4);
            
            $candidates[] = [
                'trend_label' => $token,
                'why_trending' => sprintf(
                    'Recent posts (%d in last %d days) outpaced baseline (%d in prior %d days).',
                    $recent,
                    $recentDays,
                    $baseline,
                    $baselineDays
                ),
                'evidence' => [
                    'recent_posts' => $recent,
                    'baseline_posts' => $baseline,
                    'velocity_ratio' => round($velocity, 2),
                    'avg_engagement' => round($avgEngagement, 2),
                ],
                'confidence' => round($confidence, 2),
            ];
        }
        
        usort($candidates, function ($a, $b) {
            $va = (float) ($a['evidence']['velocity_ratio'] ?? 0.0);
            $vb = (float) ($b['evidence']['velocity_ratio'] ?? 0.0);
            if ($va === $vb) {
                $ra = (int) ($a['evidence']['recent_posts'] ?? 0);
                $rb = (int) ($b['evidence']['recent_posts'] ?? 0);
                return $rb <=> $ra;
            }
            return $vb <=> $va;
        });
        
        return [
            'trends' => array_slice($candidates, 0, $limit),
            'meta' => [
                'seed' => trim($industry) !== '' ? $industry : $query,
                'items_considered' => $totalItems,
                'days_back' => $daysBack,
                'recent_days' => $recentDays,
                'reader' => 'canonical',
            ],
        ];
    }
}
