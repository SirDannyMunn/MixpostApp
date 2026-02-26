<?php

namespace App\Services\Ai;

use App\Models\Template;
use Illuminate\Support\Collection;

class TemplateSelector
{
    /**
     * Template types.
     */
    public const TYPE_POST = 'post';
    public const TYPE_COMMENT = 'comment';

    /**
     * Backward-compatible simple select. Returns best template or null.
     */
    public function select(string $organizationId, ?string $intent, ?string $funnelStage, string $platform, string $templateType = self::TYPE_POST): ?Template
    {
        [$best] = $this->selectBest($organizationId, $intent, $funnelStage, $platform, $templateType);
        return $best;
    }

    /**
     * Deterministically select best template with debug scoring.
     * Returns [Template|null $best, array $candidates, array $scores]
     */
    public function selectBest(string $organizationId, ?string $intent, ?string $funnelStage, string $platform, string $templateType = self::TYPE_POST): array
    {
        $platform = strtolower((string) $platform ?: 'generic');
        $intent = $intent ? strtolower($intent) : null;
        $funnelStage = $funnelStage ? strtolower($funnelStage) : null;

        $query = Template::query()
            ->where(function($q) use ($organizationId) {
                $q->where('organization_id', $organizationId)
                  ->orWhereNull('organization_id')
                  ->orWhere('is_public', true);
            })
            ->where('template_type', $templateType);

        $rows = $query->get();

        $candidates = [];
        $scores = [];

        foreach ($rows as $tpl) {
            $tplPlatform = null;
            $tplIntent = null;
            $tplFunnels = [];

            // Platform from column or template_data
            $data = is_array($tpl->template_data) ? $tpl->template_data : [];
            // Require minimal structure to be eligible
            $structure = $data['structure'] ?? ($data['template']['structure'] ?? []);
            if (!is_array($structure) || count($structure) === 0) {
                continue;
            }
            $tplPlatform = strtolower((string) ($tpl->platform ?? ($data['platform'] ?? ($data['routing']['platform'] ?? 'generic'))));

            // Intent from explicit column if exists, otherwise use category
            $tplIntent = strtolower((string) ($tpl->intent ?? $tpl->category ?? ''));

            // Supported funnels from column or template_data
            $tplFunnels = $data['supported_funnels'] ?? ($data['routing']['supported_funnels'] ?? []);
            if (!is_array($tplFunnels)) { $tplFunnels = []; }
            $tplFunnels = array_values(array_filter(array_map(fn($v) => strtolower((string) $v), $tplFunnels)));

            // Eligibility checks
            $eligible = true;
            // platform eligibility: exact or generic qualifies
            $platformEligible = ($tplPlatform === $platform) || ($tplPlatform === 'generic');
            $eligible = $eligible && $platformEligible;

            // intent eligibility: exact match or flex/all or empty intent considered flexible
            $intentEligible = true;
            if ($intent) {
                $intentEligible = ($tplIntent === $intent) || in_array($tplIntent, ['all','flex','any',''], true);
            }
            $eligible = $eligible && $intentEligible;

            // funnel eligibility: contains funnel or any
            $funnelEligible = true;
            if ($funnelStage) {
                $funnelEligible = in_array($funnelStage, $tplFunnels, true) || in_array('any', $tplFunnels, true) || empty($tplFunnels);
            }
            $eligible = $eligible && $funnelEligible;

            if (!$eligible) {
                continue;
            }

            // Scoring
            $score = 0;
            $breakdown = [
                'platform' => 0,
                'intent' => 0,
                'funnel' => 0,
                'org_custom' => 0,
                'usage_bonus' => 0,
            ];

            // Prefer exact platform, allow generic with smaller weight
            if ($tplPlatform === $platform) { $score += 5; $breakdown['platform'] = 5; }
            elseif ($tplPlatform === 'generic') { $score += 3; $breakdown['platform'] = 3; }

            // Intent
            if ($intent) {
                if ($tplIntent === $intent) { $score += 3; $breakdown['intent'] = 3; }
                elseif (in_array($tplIntent, ['all','flex','any',''], true)) { $score += 1; $breakdown['intent'] = 1; }
            }

            // Funnel
            if ($funnelStage) {
                if (in_array($funnelStage, $tplFunnels, true)) { $score += 2; $breakdown['funnel'] = 2; }
                elseif (in_array('any', $tplFunnels, true) || empty($tplFunnels)) { $score += 1; $breakdown['funnel'] = 1; }
            }

            // Prefer org custom (is_public = false)
            if (!$tpl->is_public) { $score += 1; $breakdown['org_custom'] = 1; }

            $usage = (int) ($tpl->usage_count ?? 0);
            $scores[(string) $tpl->id] = [
                'score' => $score,
                'breakdown' => $breakdown,
                'usage' => $usage,
                'updated_at' => (string) ($tpl->updated_at ?? ''),
                'platform' => $tplPlatform,
                'intent' => $tplIntent,
                'funnels' => $tplFunnels,
            ];
            $candidates[] = $tpl;
        }

        if (empty($candidates)) {
            return [null, [], $scores];
        }

        // Sort by score desc, then usage_count desc, then updated_at desc
        usort($candidates, function($a, $b) use ($scores) {
            $sa = $scores[(string) $a->id]['score'] ?? 0;
            $sb = $scores[(string) $b->id]['score'] ?? 0;
            if ($sa !== $sb) return $sb <=> $sa;
            $ua = $scores[(string) $a->id]['usage'] ?? 0;
            $ub = $scores[(string) $b->id]['usage'] ?? 0;
            if ($ua !== $ub) return $ub <=> $ua;
            $ta = strtotime((string) ($scores[(string) $a->id]['updated_at'] ?? '')) ?: 0;
            $tb = strtotime((string) ($scores[(string) $b->id]['updated_at'] ?? '')) ?: 0;
            return $tb <=> $ta;
        });

        $best = $candidates[0] ?? null;
        return [$best, array_map(fn($t) => (string) $t->id, $candidates), $scores];
    }
}
