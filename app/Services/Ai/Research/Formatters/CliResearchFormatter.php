<?php

namespace App\Services\Ai\Research\Formatters;

use App\Services\Ai\Research\DTO\ResearchResult;
use App\Enums\ResearchStage;

class CliResearchFormatter
{
    /**
     * Format research result for CLI output.
     */
    public function format(ResearchResult $result): string
    {
        return match ($result->stage) {
            ResearchStage::DEEP_RESEARCH => $this->formatDeepResearch($result),
            ResearchStage::ANGLE_HOOKS => $this->formatAngleHooks($result),
            ResearchStage::TREND_DISCOVERY => $this->formatTrendDiscovery($result),
            ResearchStage::SATURATION_OPPORTUNITY => $this->formatSaturationOpportunity($result),
        };
    }

    protected function formatDeepResearch(ResearchResult $result): string
    {
        $output = [];
        $output[] = 'Question: ' . $result->question;
        $output[] = '';

        // Dominant claims
        $output[] = 'Dominant claims:';
        if (empty($result->dominantClaims)) {
            $output[] = '  (none)';
        } else {
            foreach ($result->dominantClaims as $claim) {
                $output[] = '  - ' . trim((string) $claim);
            }
        }
        $output[] = '';

        // Points of disagreement
        $output[] = 'Points of disagreement:';
        if (empty($result->pointsOfDisagreement)) {
            $output[] = '  (none)';
        } else {
            foreach ($result->pointsOfDisagreement as $point) {
                $output[] = '  - ' . trim((string) $point);
            }
        }
        $output[] = '';

        // Saturated angles
        $output[] = 'Saturated angles:';
        if (empty($result->saturatedAngles)) {
            $output[] = '  (none)';
        } else {
            foreach ($result->saturatedAngles as $angle) {
                $output[] = '  - ' . trim((string) $angle);
            }
        }
        $output[] = '';

        // Emerging angles
        $output[] = 'Emerging angles:';
        if (empty($result->emergingAngles)) {
            $output[] = '  (none)';
        } else {
            foreach ($result->emergingAngles as $angle) {
                $output[] = '  - ' . trim((string) $angle);
            }
        }
        $output[] = '';

        // Sample excerpts
        $output[] = 'Sample excerpts:';
        if (empty($result->sampleExcerpts)) {
            $output[] = '  (none)';
        } else {
            foreach ($result->sampleExcerpts as $excerpt) {
                $text = trim((string) ($excerpt['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $source = (string) ($excerpt['source'] ?? 'other');
                $confidence = isset($excerpt['confidence']) ? (float) $excerpt['confidence'] : null;
                $line = '  - [' . $source . '] ' . $text;
                if ($confidence !== null) {
                    $line .= ' (confidence=' . number_format($confidence, 2) . ')';
                }
                $output[] = $line;
            }
        }
        $output[] = '';

        if ($result->snapshotId) {
            $output[] = 'Snapshot ID: ' . $result->snapshotId;
        }

        return implode("\n", $output);
    }

    protected function formatAngleHooks(ResearchResult $result): string
    {
        $output = [];
        $output[] = 'Question: ' . $result->question;
        $output[] = 'Stage: angle_hooks';
        $output[] = '';

        $output[] = 'Hooks:';
        if (empty($result->hooks)) {
            $output[] = '  (none)';
        } else {
            foreach ($result->hooks as $hook) {
                $text = trim((string) ($hook['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $line = '  - ' . $text;
                $arch = trim((string) ($hook['archetype'] ?? ''));
                if ($arch !== '') {
                    $line .= ' [' . $arch . ']';
                }
                $output[] = $line;
            }
        }

        return implode("\n", $output);
    }

    protected function formatTrendDiscovery(ResearchResult $result): string
    {
        $output = [];
        $output[] = 'Question: ' . $result->question;
        $industry = (string) ($result->metadata['industry'] ?? '');
        if ($industry !== '') {
            $output[] = 'Industry: ' . $industry;
        }
        $output[] = 'Stage: trend_discovery';
        $output[] = '';

        $output[] = 'Trend candidates:';
        if (empty($result->trends)) {
            $output[] = '  (none)';
        } else {
            foreach ($result->trends as $trend) {
                $label = trim((string) ($trend['trend_label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $confidence = isset($trend['confidence']) ? (float) $trend['confidence'] : null;
                $line = '  - ' . $label;
                if ($confidence !== null) {
                    $line .= ' (confidence=' . number_format($confidence, 2) . ')';
                }
                $output[] = $line;

                $why = trim((string) ($trend['why_trending'] ?? ''));
                if ($why !== '') {
                    $output[] = '    ' . $why;
                }

                $evidence = (array) ($trend['evidence'] ?? []);
                if (!empty($evidence)) {
                    $output[] = '    evidence: ' . json_encode($evidence, JSON_UNESCAPED_UNICODE);
                }
            }
        }

        return implode("\n", $output);
    }

    protected function formatSaturationOpportunity(ResearchResult $result): string
    {
        $report = $result->saturationReport;
        $output = [];
        
        $output[] = 'Question: ' . $result->question;
        $output[] = 'Stage: saturation_opportunity';
        $output[] = '';

        // Decision
        $decision = (array) ($report['decision'] ?? []);
        $recommendation = strtoupper((string) ($decision['recommendation'] ?? 'CAUTIOUS_GO'));
        $opportunityScore = (int) ($decision['opportunity_score'] ?? 50);
        $confidence = isset($decision['confidence']) ? (float) $decision['confidence'] : null;
        $summary = trim((string) ($decision['summary'] ?? ''));

        $output[] = 'Decision: ' . str_replace('_', ' ', $recommendation);
        $output[] = 'Opportunity Score: ' . $opportunityScore . '/100';
        if ($confidence !== null) {
            $output[] = 'Confidence: ' . number_format($confidence, 2);
        }
        if ($summary !== '') {
            $output[] = '';
            $output[] = $summary;
        }
        $output[] = '';

        // Key Signals
        $signals = (array) ($report['signals'] ?? []);
        $output[] = 'Key Metrics:';
        
        $volume = (array) ($signals['volume'] ?? []);
        if (!empty($volume)) {
            $recentPosts = (int) ($volume['recent_posts'] ?? 0);
            $velocityRatio = (float) ($volume['velocity_ratio'] ?? 0.0);
            $output[] = '  Volume: ' . $recentPosts . ' recent posts (velocity: ' . $velocityRatio . 'x baseline)';
        }

        $fatigue = (array) ($signals['fatigue'] ?? []);
        if (!empty($fatigue)) {
            $avgFatigue = (float) ($fatigue['avg_fatigue'] ?? 0.0);
            $repeatRate = (float) ($fatigue['repeat_rate_30d'] ?? 0.0);
            $output[] = '  Fatigue: ' . number_format($avgFatigue * 100, 0) . '% (repeat rate: ' . number_format($repeatRate * 100, 0) . '%)';
        }

        $diversity = (array) ($signals['diversity'] ?? []);
        if (!empty($diversity)) {
            $angleDiversity = (float) ($diversity['angle_diversity'] ?? 0.0);
            $hookDiversity = (float) ($diversity['hook_diversity'] ?? 0.0);
            $output[] = '  Diversity: angles=' . number_format($angleDiversity * 100, 0) . '%, hooks=' . number_format($hookDiversity * 100, 0) . '%';
        }

        $quality = (array) ($signals['quality'] ?? []);
        if (!empty($quality)) {
            $avgBuyerQuality = (float) ($quality['avg_buyer_quality'] ?? 0.0);
            $avgNoiseRisk = (float) ($quality['avg_noise_risk'] ?? 0.0);
            $output[] = '  Quality: buyer_quality=' . number_format($avgBuyerQuality * 100, 0) . '%, noise_risk=' . number_format($avgNoiseRisk * 100, 0) . '%';
        }

        $output[] = '';

        // Saturated Patterns
        $saturatedPatterns = (array) ($report['saturated_patterns'] ?? []);
        if (!empty($saturatedPatterns)) {
            $output[] = 'Saturated Patterns (top 3):';
            foreach (array_slice($saturatedPatterns, 0, 3) as $i => $pattern) {
                $label = trim((string) ($pattern['label'] ?? ''));
                $whySaturated = trim((string) ($pattern['why_saturated'] ?? ''));
                $evidence = (array) ($pattern['evidence'] ?? []);
                
                if ($label !== '') {
                    $output[] = '  ' . ($i + 1) . '. ' . $label;
                    if ($whySaturated !== '') {
                        $output[] = '     ' . $whySaturated;
                    }
                    $itemCount = (int) ($evidence['item_count'] ?? 0);
                    $repeatRate = isset($evidence['repeat_rate_30d']) ? round((float) $evidence['repeat_rate_30d'] * 100, 0) : null;
                    if ($itemCount > 0 || $repeatRate !== null) {
                        $evidenceLine = '     Evidence: ' . $itemCount . ' instances';
                        if ($repeatRate !== null) {
                            $evidenceLine .= ', ' . $repeatRate . '% repeat rate';
                        }
                        $output[] = $evidenceLine;
                    }
                }
            }
            $output[] = '';
        }

        // White Space Opportunities
        $whiteSpaceOpportunities = (array) ($report['white_space_opportunities'] ?? []);
        if (!empty($whiteSpaceOpportunities)) {
            $output[] = 'White Space Opportunities (top 5):';
            foreach (array_slice($whiteSpaceOpportunities, 0, 5) as $i => $opportunity) {
                $angle = trim((string) ($opportunity['angle'] ?? ''));
                $whyOpen = trim((string) ($opportunity['why_open'] ?? ''));
                $recommendedHooks = (array) ($opportunity['recommended_hook_archetypes'] ?? []);
                $evidence = (array) ($opportunity['evidence'] ?? []);
                
                if ($angle !== '') {
                    $output[] = '  ' . ($i + 1) . '. ' . $angle;
                    if ($whyOpen !== '') {
                        $output[] = '     ' . $whyOpen;
                    }
                    if (!empty($recommendedHooks)) {
                        $output[] = '     Recommended hooks: ' . implode(', ', $recommendedHooks);
                    }
                    $avgBuyerQuality = isset($evidence['avg_buyer_quality']) ? round((float) $evidence['avg_buyer_quality'] * 100, 0) : null;
                    $itemCount = (int) ($evidence['item_count'] ?? 0);
                    if ($avgBuyerQuality !== null || $itemCount > 0) {
                        $evidenceLine = '     Evidence: ' . $itemCount . ' posts';
                        if ($avgBuyerQuality !== null) {
                            $evidenceLine .= ', ' . $avgBuyerQuality . '% buyer quality';
                        }
                        $output[] = $evidenceLine;
                    }
                }
            }
            $output[] = '';
        }

        // Risks
        $risks = (array) ($report['risks'] ?? []);
        if (!empty($risks)) {
            $output[] = 'Risks:';
            foreach ($risks as $riskItem) {
                $risk = trim((string) ($riskItem['risk'] ?? ''));
                $severity = trim((string) ($riskItem['severity'] ?? ''));
                $mitigation = trim((string) ($riskItem['mitigation'] ?? ''));
                
                if ($risk !== '') {
                    $riskLine = '  - ' . $risk;
                    if ($severity !== '') {
                        $riskLine .= ' [' . strtoupper($severity) . ']';
                    }
                    $output[] = $riskLine;
                    if ($mitigation !== '') {
                        $output[] = '    → ' . $mitigation;
                    }
                }
            }
            $output[] = '';
        }

        if ($result->snapshotId) {
            $output[] = 'Snapshot ID: ' . $result->snapshotId;
        }

        return implode("\n", $output);
    }

    /**
     * Format debug trace information.
     */
    public function formatTrace(ResearchResult $result, int $totalMs): string
    {
        $output = [];
        $output[] = '';
        $output[] = 'Trace:';

        $counts = (array) ($result->debug['counts'] ?? []);
        $timings = (array) ($result->debug['timings'] ?? []);
        $model = (string) ($result->debug['model'] ?? '');

        $output[] = '  items=' . (string) ($counts['items'] ?? '0') . ', clusters=' . (string) ($counts['clusters'] ?? '0');

        if (isset($counts['kb_items'])) {
            $mediaTypes = (array) ($counts['media_types'] ?? []);
            $output[] = '  kb_items=' . (string) ($counts['kb_items'] ?? '0') . ', media_types=' . implode(',', $mediaTypes);
        }

        if ($model !== '') {
            $output[] = '  model=' . $model;
        }

        if (!empty($timings)) {
            $output[] = '  retrieval_ms=' . (string) ($timings['retrieval_ms'] ?? '0') .
                       ', compose_ms=' . (string) ($timings['compose_ms'] ?? '0') .
                       ', total_ms=' . $totalMs;
        } else {
            $output[] = '  total_ms=' . $totalMs;
        }

        if ($result->snapshotId !== null) {
            $output[] = '  snapshot_id=' . $result->snapshotId;
        }

        return implode("\n", $output);
    }
}
