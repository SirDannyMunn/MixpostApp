<?php

namespace Tests\Unit\Services\Voice;

use App\Services\Voice\VoiceTraitsMerger;
use Tests\TestCase;

class VoiceTraitsMergerTest extends TestCase
{
    public function test_merges_enum_fields_with_majority_vote(): void
    {
        $batch1 = [
            'formality' => 'casual',
            'sentence_length' => 'short',
        ];
        $batch2 = [
            'formality' => 'casual',
            'sentence_length' => 'medium',
        ];
        $batch3 = [
            'formality' => 'casual',
            'sentence_length' => 'short',
        ];

        $merged = VoiceTraitsMerger::merge([$batch1, $batch2, $batch3]);

        $this->assertEquals('casual', $merged['formality']); // 3/3
        $this->assertEquals('short', $merged['sentence_length']); // 2/3
    }

    public function test_returns_null_on_enum_tie(): void
    {
        $batch1 = ['formality' => 'casual'];
        $batch2 = ['formality' => 'formal'];

        $merged = VoiceTraitsMerger::merge([$batch1, $batch2]);

        $this->assertNull($merged['formality']); // Tie
    }

    public function test_merges_lists_with_frequency_threshold(): void
    {
        $batch1 = ['style_signatures' => ['sig1', 'sig2', 'sig3']];
        $batch2 = ['style_signatures' => ['sig1', 'sig2', 'sig4']];
        $batch3 = ['style_signatures' => ['sig1', 'sig5']];

        $merged = VoiceTraitsMerger::merge([$batch1, $batch2, $batch3]);

        // sig1 appears in 3/3 batches (100%)
        // sig2 appears in 2/3 batches (66%)
        // sig3 appears in 1/3 batches (33%)
        // sig4 appears in 1/3 batches (33%)
        // sig5 appears in 1/3 batches (33%)
        // Threshold is 30%, so sig1, sig2, sig3, sig4, sig5 should all pass
        $this->assertContains('sig1', $merged['style_signatures']);
        $this->assertContains('sig2', $merged['style_signatures']);
    }

    public function test_caps_list_length_at_20(): void
    {
        $manyItems = array_map(fn($i) => "item{$i}", range(1, 30));
        
        $batch = ['style_signatures' => $manyItems];

        $merged = VoiceTraitsMerger::merge([$batch]);

        $this->assertLessThanOrEqual(20, count($merged['style_signatures']));
    }

    public function test_merges_format_rules(): void
    {
        $batch1 = [
            'format_rules' => [
                'casing' => 'lowercase',
                'line_breaks' => 'heavy',
            ],
        ];
        $batch2 = [
            'format_rules' => [
                'casing' => 'lowercase',
                'line_breaks' => 'normal',
            ],
        ];

        $merged = VoiceTraitsMerger::merge([$batch1, $batch2]);

        $this->assertEquals('lowercase', $merged['format_rules']['casing']);
        // line_breaks is tied, should be null
        $this->assertNull($merged['format_rules']['line_breaks']);
    }

    public function test_merges_persona_contract_lists(): void
    {
        $batch1 = [
            'persona_contract' => [
                'in_group' => ['entrepreneurs', 'creators'],
                'out_group' => ['traditionalists'],
            ],
        ];
        $batch2 = [
            'persona_contract' => [
                'in_group' => ['entrepreneurs', 'innovators'],
                'out_group' => ['traditionalists'],
            ],
        ];
        $batch3 = [
            'persona_contract' => [
                'in_group' => ['entrepreneurs'],
                'out_group' => [],
            ],
        ];

        $merged = VoiceTraitsMerger::merge([$batch1, $batch2, $batch3]);

        // entrepreneurs: 3/3 (100%)
        // creators: 1/3 (33%)
        // innovators: 1/3 (33%)
        $this->assertContains('entrepreneurs', $merged['persona_contract']['in_group']);
        
        // traditionalists: 2/3 (66%)
        $this->assertContains('traditionalists', $merged['persona_contract']['out_group']);
    }

    public function test_merges_safety_takes_highest_toxicity_risk(): void
    {
        $batch1 = ['safety' => ['toxicity_risk' => 'low', 'notes' => null]];
        $batch2 = ['safety' => ['toxicity_risk' => 'high', 'notes' => null]];
        $batch3 = ['safety' => ['toxicity_risk' => 'medium', 'notes' => null]];

        $merged = VoiceTraitsMerger::merge([$batch1, $batch2, $batch3]);

        $this->assertEquals('high', $merged['safety']['toxicity_risk']);
    }

    public function test_compute_consistency_metrics_with_high_agreement(): void
    {
        $batch1 = [
            'formality' => 'casual',
            'style_signatures' => ['sig1', 'sig2', 'sig3'],
            'do_not_do' => ['avoid1', 'avoid2'],
        ];
        $batch2 = [
            'formality' => 'casual',
            'style_signatures' => ['sig1', 'sig2', 'sig4'],
            'do_not_do' => ['avoid1', 'avoid2'],
        ];

        $metrics = VoiceTraitsMerger::computeConsistencyMetrics([$batch1, $batch2]);

        $this->assertArrayHasKey('enum_agreement', $metrics);
        $this->assertArrayHasKey('list_overlap', $metrics);
        $this->assertArrayHasKey('consistency', $metrics);
        
        $this->assertGreaterThan(0.5, $metrics['enum_agreement']); // High agreement
        $this->assertGreaterThan(0.3, $metrics['list_overlap']); // Decent overlap
    }

    public function test_merge_single_batch_returns_original(): void
    {
        $batch = [
            'schema_version' => '2.0',
            'description' => 'Test',
            'formality' => 'casual',
            'style_signatures' => ['sig1', 'sig2', 'sig3'],
        ];

        $merged = VoiceTraitsMerger::merge([$batch]);

        $this->assertEquals('2.0', $merged['schema_version']);
        $this->assertEquals('Test', $merged['description']);
        $this->assertEquals('casual', $merged['formality']);
        $this->assertEquals(['sig1', 'sig2', 'sig3'], $merged['style_signatures']);
    }

    public function test_merge_empty_array_returns_defaults(): void
    {
        $merged = VoiceTraitsMerger::merge([]);

        $this->assertIsArray($merged);
        $this->assertEquals('2.0', $merged['schema_version']);
    }
}
