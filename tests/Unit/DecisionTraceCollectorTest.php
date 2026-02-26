<?php

use App\Services\Ai\Generation\DecisionTraceCollector;
use PHPUnit\Framework\TestCase;

final class DecisionTraceCollectorTest extends TestCase
{
    public function test_it_records_entries(): void
    {
        $collector = new DecisionTraceCollector();
        $collector->record('prompt_signals', 'PromptSignalExtractor', 'none_detected');
        $collector->record('ci_vector_search', 'CreativeIntelligenceRecommender', '0_candidates', 'no_hits', ['vector_k' => 50]);

        $this->assertSame([
            [
                'step' => 'prompt_signals',
                'actor' => 'PromptSignalExtractor',
                'result' => 'none_detected',
            ],
            [
                'step' => 'ci_vector_search',
                'actor' => 'CreativeIntelligenceRecommender',
                'result' => '0_candidates',
                'reason' => 'no_hits',
                'metadata' => ['vector_k' => 50],
            ],
        ], $collector->all());
    }
}
