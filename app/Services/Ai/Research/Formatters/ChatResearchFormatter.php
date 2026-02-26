<?php

namespace App\Services\Ai\Research\Formatters;

use App\Services\Ai\Research\DTO\ResearchResult;
use App\Enums\ResearchStage;

class ChatResearchFormatter
{
    /**
     * Format research result for chat/API JSON response.
     */
    public function format(ResearchResult $result): array
    {
        return match ($result->stage) {
            ResearchStage::DEEP_RESEARCH => $this->formatDeepResearch($result),
            ResearchStage::ANGLE_HOOKS => $this->formatAngleHooks($result),
            ResearchStage::TREND_DISCOVERY => $this->formatTrendDiscovery($result),
            ResearchStage::SATURATION_OPPORTUNITY => $this->formatSaturationOpportunity($result),
        };
    }

    /**
     * Format deep research as Markdown string.
     */
    protected function formatDeepResearch(ResearchResult $result): array
    {
        $md = '';

        // Dominant Claims
        if (!empty($result->dominantClaims)) {
            $md .= "## Dominant Claims\n\n";
            foreach ($result->dominantClaims as $claim) {
                $md .= "- " . trim($claim) . "\n";
            }
            $md .= "\n";
        }

        // Points of Disagreement
        if (!empty($result->pointsOfDisagreement)) {
            $md .= "## Points of Disagreement\n\n";
            foreach ($result->pointsOfDisagreement as $point) {
                $md .= "- " . trim($point) . "\n";
            }
            $md .= "\n";
        }

        // Emerging Angles
        if (!empty($result->emergingAngles)) {
            $md .= "## Emerging Angles\n\n";
            foreach ($result->emergingAngles as $angle) {
                $md .= "- " . trim($angle) . "\n";
            }
            $md .= "\n";
        }

        // Saturated Angles
        if (!empty($result->saturatedAngles)) {
            $md .= "## Saturated Angles\n\n";
            foreach ($result->saturatedAngles as $angle) {
                $md .= "- " . trim($angle) . "\n";
            }
            $md .= "\n";
        }

        // Sample Excerpts
        if (!empty($result->sampleExcerpts)) {
            $md .= "## Sample Excerpts\n\n";
            foreach ($result->sampleExcerpts as $excerpt) {
                $text = trim((string) ($excerpt['text'] ?? ''));
                $source = trim((string) ($excerpt['source'] ?? 'unknown'));
                $confidence = isset($excerpt['confidence']) ? round((float) $excerpt['confidence'], 2) : null;

                if ($text !== '') {
                    $md .= "**[{$source}]**";
                    if ($confidence !== null) {
                        $md .= " _(confidence: {$confidence})_";
                    }
                    $md .= "\n\n";
                    $md .= $text . "\n\n";
                }
            }
        }

        return [
            'formatted_report' => trim($md),
            'raw' => [
                'dominant_claims' => $result->dominantClaims,
                'points_of_disagreement' => $result->pointsOfDisagreement,
                'emerging_angles' => $result->emergingAngles,
                'saturated_angles' => $result->saturatedAngles,
                'sample_excerpts' => $result->sampleExcerpts,
            ],
        ];
    }

    /**
     * Format angle & hooks as Markdown string.
     */
    protected function formatAngleHooks(ResearchResult $result): array
    {
        $md = '';

        if (!empty($result->hooks)) {
            $md .= "## Creative Hooks\n\n";
            
            foreach ($result->hooks as $i => $hook) {
                // Support both 'text' (new format) and 'hook_text' (legacy format)
                $hookText = trim((string) ($hook['text'] ?? $hook['hook_text'] ?? ''));
                $archetype = trim((string) ($hook['archetype'] ?? ''));
                $confidence = isset($hook['confidence']) ? round((float) $hook['confidence'], 2) : null;

                if ($hookText !== '') {
                    $md .= "### Hook " . ($i + 1);
                    if ($archetype !== '') {
                        $md .= " - {$archetype}";
                    }
                    $md .= "\n\n";
                    $md .= $hookText . "\n\n";
                    
                    if ($confidence !== null) {
                        $md .= "_Confidence: {$confidence}_\n\n";
                    }
                }
            }
        }

        return [
            'formatted_report' => trim($md),
            'raw' => [
                'hooks' => $result->hooks,
            ],
        ];
    }

    /**
     * Format trend discovery as Markdown string.
     */
    protected function formatTrendDiscovery(ResearchResult $result): array
    {
        $md = "## Trending Topics\n\n";
        $industry = (string) ($result->metadata['industry'] ?? '');

        if ($industry !== '') {
            $md .= "_Industry: {$industry}_\n\n";
        }

        if (!empty($result->trends)) {
            foreach ($result->trends as $i => $trend) {
                $label = trim((string) ($trend['trend_label'] ?? ''));
                $why = trim((string) ($trend['why_trending'] ?? ''));
                $evidence = (array) ($trend['evidence'] ?? []);
                $confidence = isset($trend['confidence']) ? round((float) $trend['confidence'], 2) : null;

                if ($label !== '') {
                    $md .= "### " . ($i + 1) . ". {$label}\n\n";
                    
                    if ($why !== '') {
                        $md .= $why . "\n\n";
                    }

                    if (!empty($evidence)) {
                        $md .= "**Evidence:**\n";
                        foreach ($evidence as $key => $value) {
                            $formattedKey = ucwords(str_replace('_', ' ', $key));
                            $md .= "- {$formattedKey}: {$value}\n";
                        }
                        $md .= "\n";
                    }

                    if ($confidence !== null) {
                        $md .= "_Confidence: {$confidence}_\n\n";
                    }
                }
            }
        } else {
            $md .= "_No trending topics found._\n";
        }

        return [
            'formatted_report' => trim($md),
            'raw' => [
                'query' => $result->question,
                'industry' => $industry,
                'trends' => $result->trends,
            ],
        ];
    }

    /**
     * Build complete chat response payload.
     */
    public function buildChatResponse(ResearchResult $result, string $message = ''): array
    {
        if ($message === '') {
            $message = $this->generateDefaultMessage($result);
        }

        return [
            'response' => $message,
            'report' => $this->format($result),
            'metadata' => [
                'mode' => [
                    'type' => 'research',
                    'subtype' => $result->stage->value,
                ],
                'research_stage' => $result->stage->value,
                'snapshot_id' => $result->snapshotId,
                'intent' => $result->metadata['intent'] ?? null,
                'funnel_stage' => $result->metadata['funnel_stage'] ?? null,
                'run_id' => $result->metadata['run_id'] ?? null,
            ],
        ];
    }

    protected function generateDefaultMessage(ResearchResult $result): string
    {
        return match ($result->stage) {
            ResearchStage::DEEP_RESEARCH => 'Here is your research report analyzing existing content.',
            ResearchStage::ANGLE_HOOKS => 'Here are creative hooks based on your research.',
            ResearchStage::TREND_DISCOVERY => 'Here are the trending topics discovered.',
            ResearchStage::SATURATION_OPPORTUNITY => 'Here is your saturation and opportunity analysis.',
        };
    }

    /**
     * Format saturation & opportunity analysis as Markdown string.
     */
    protected function formatSaturationOpportunity(ResearchResult $result): array
    {
        $report = $result->saturationReport;
        $md = '';

        // Decision
        $decision = (array) ($report['decision'] ?? []);
        $recommendation = (string) ($decision['recommendation'] ?? 'cautious_go');
        $opportunityScore = (int) ($decision['opportunity_score'] ?? 50);
        $confidence = isset($decision['confidence']) ? round((float) $decision['confidence'], 2) : null;
        $summary = (string) ($decision['summary'] ?? '');

        $md .= "## Decision\n\n";
        $md .= "**Recommendation:** " . strtoupper(str_replace('_', ' ', $recommendation)) . "\n\n";
        
        if ($summary !== '') {
            $md .= $summary . "\n\n";
        }

        // Opportunity Score
        $md .= "## Opportunity Score\n\n";
        $md .= "**{$opportunityScore}/100**";
        if ($confidence !== null) {
            $md .= " _(confidence: {$confidence})_";
        }
        $md .= "\n\n";

        // Signals Summary
        $signals = (array) ($report['signals'] ?? []);
        if (!empty($signals)) {
            $md .= "### Key Metrics\n\n";
            
            $volume = (array) ($signals['volume'] ?? []);
            if (!empty($volume)) {
                $recentPosts = (int) ($volume['recent_posts'] ?? 0);
                $velocityRatio = (float) ($volume['velocity_ratio'] ?? 0.0);
                $md .= "- **Volume:** {$recentPosts} recent posts (velocity: {$velocityRatio}x baseline)\n";
            }

            $fatigue = (array) ($signals['fatigue'] ?? []);
            if (!empty($fatigue)) {
                $avgFatigue = (float) ($fatigue['avg_fatigue'] ?? 0.0);
                $md .= "- **Fatigue:** " . ($avgFatigue * 100) . "%\n";
            }

            $diversity = (array) ($signals['diversity'] ?? []);
            if (!empty($diversity)) {
                $angleDiversity = (float) ($diversity['angle_diversity'] ?? 0.0);
                $md .= "- **Angle Diversity:** " . ($angleDiversity * 100) . "%\n";
            }

            $quality = (array) ($signals['quality'] ?? []);
            if (!empty($quality)) {
                $avgBuyerQuality = (float) ($quality['avg_buyer_quality'] ?? 0.0);
                $md .= "- **Avg Buyer Quality:** " . ($avgBuyerQuality * 100) . "%\n";
            }

            $md .= "\n";
        }

        // Saturated Patterns
        $saturatedPatterns = (array) ($report['saturated_patterns'] ?? []);
        if (!empty($saturatedPatterns)) {
            $md .= "## Why it's saturated\n\n";
            $md .= "**Top saturated patterns:**\n\n";

            foreach (array_slice($saturatedPatterns, 0, 3) as $i => $pattern) {
                $label = trim((string) ($pattern['label'] ?? ''));
                $whySaturated = trim((string) ($pattern['why_saturated'] ?? ''));
                $evidence = (array) ($pattern['evidence'] ?? []);

                if ($label !== '') {
                    $md .= ($i + 1) . ". **{$label}**\n";
                    if ($whySaturated !== '') {
                        $md .= "   - {$whySaturated}\n";
                    }
                    
                    $itemCount = (int) ($evidence['item_count'] ?? 0);
                    $repeatRate = isset($evidence['repeat_rate_30d']) ? round((float) $evidence['repeat_rate_30d'] * 100, 0) : null;
                    if ($itemCount > 0 || $repeatRate !== null) {
                        $md .= "   - Evidence: {$itemCount} instances";
                        if ($repeatRate !== null) {
                            $md .= ", {$repeatRate}% repeat rate";
                        }
                        $md .= "\n";
                    }
                    $md .= "\n";
                }
            }
        }

        // White Space Opportunities
        $whiteSpaceOpportunities = (array) ($report['white_space_opportunities'] ?? []);
        if (!empty($whiteSpaceOpportunities)) {
            $md .= "## White space to exploit\n\n";
            $md .= "**Opportunities for differentiation:**\n\n";

            foreach (array_slice($whiteSpaceOpportunities, 0, 7) as $i => $opportunity) {
                $angle = trim((string) ($opportunity['angle'] ?? ''));
                $whyOpen = trim((string) ($opportunity['why_open'] ?? ''));
                $recommendedHooks = (array) ($opportunity['recommended_hook_archetypes'] ?? []);
                $evidence = (array) ($opportunity['evidence'] ?? []);

                if ($angle !== '') {
                    $md .= "### " . ($i + 1) . ". {$angle}\n\n";
                    
                    if ($whyOpen !== '') {
                        $md .= $whyOpen . "\n\n";
                    }

                    if (!empty($recommendedHooks)) {
                        $md .= "**Recommended hooks:** " . implode(', ', $recommendedHooks) . "\n\n";
                    }

                    if (!empty($evidence)) {
                        $avgBuyerQuality = isset($evidence['avg_buyer_quality']) ? round((float) $evidence['avg_buyer_quality'] * 100, 0) : null;
                        $itemCount = (int) ($evidence['item_count'] ?? 0);
                        if ($avgBuyerQuality !== null || $itemCount > 0) {
                            $md .= "_Evidence: {$itemCount} posts";
                            if ($avgBuyerQuality !== null) {
                                $md .= ", {$avgBuyerQuality}% buyer quality";
                            }
                            $md .= "_\n\n";
                        }
                    }
                }
            }
        }

        // Risks & Mitigations
        $risks = (array) ($report['risks'] ?? []);
        if (!empty($risks)) {
            $md .= "## Risks & mitigations\n\n";

            foreach ($risks as $riskItem) {
                $risk = trim((string) ($riskItem['risk'] ?? ''));
                $severity = trim((string) ($riskItem['severity'] ?? ''));
                $mitigation = trim((string) ($riskItem['mitigation'] ?? ''));

                if ($risk !== '') {
                    $md .= "**{$risk}**";
                    if ($severity !== '') {
                        $severityUpper = strtoupper($severity);
                        $md .= " _{$severityUpper}_";
                    }
                    $md .= "\n";
                    
                    if ($mitigation !== '') {
                        $md .= "- {$mitigation}\n";
                    }
                    $md .= "\n";
                }
            }
        }

        // Evidence Excerpts (optional)
        $evidence = (array) ($report['evidence'] ?? []);
        $excerpts = (array) ($evidence['representative_excerpts'] ?? []);
        if (!empty($excerpts)) {
            $md .= "## Evidence\n\n";

            foreach ($excerpts as $excerpt) {
                $text = trim((string) ($excerpt['text'] ?? ''));
                $source = trim((string) ($excerpt['source'] ?? 'unknown'));
                $excerptConfidence = isset($excerpt['confidence']) ? round((float) $excerpt['confidence'], 2) : null;

                if ($text !== '') {
                    $md .= "**[{$source}]**";
                    if ($excerptConfidence !== null) {
                        $md .= " _(quality: {$excerptConfidence})_";
                    }
                    $md .= "\n\n";
                    $md .= $text . "\n\n";
                }
            }
        }

        return [
            'formatted_report' => trim($md),
            'raw' => $report,
        ];
    }
}
