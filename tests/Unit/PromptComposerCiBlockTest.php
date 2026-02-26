<?php

use App\Services\Ai\Generation\DTO\Constraints;
use App\Services\Ai\Generation\Steps\PromptComposer;
use App\Services\Ai\GenerationContext;
use PHPUnit\Framework\TestCase;

final class PromptComposerCiBlockTest extends TestCase
{
    public function test_ci_block_is_included_in_system_prompt(): void
    {
        $composer = new PromptComposer();
        $context = new GenerationContext(
            voice: null,
            template: (object) [ 'template_data' => [ 'structure' => [
                ['section' => 'Hook'], ['section' => 'Context'], ['section' => 'Lesson'], ['section' => 'Value Points'], ['section' => 'CTA'],
            ]]],
            chunks: [],
            facts: [],
            swipes: [],
            user_context: null,
            businessSummary: null,
            options: [],
            creative_intelligence: [
                'policy' => [
                    'mode' => 'auto',
                    'hook' => 'fill',
                    'emotion' => 'fill',
                    'audience' => 'fill',
                    'allow_verbatim_hooks' => false,
                ],
                'signals' => [
                    'hook_provided' => false,
                    'emotion_provided' => false,
                    'audience_provided' => false,
                    'format_provided' => false,
                ],
                'resolved' => [
                    'audience_persona' => 'agency owners',
                    'sophistication_level' => 'intermediate',
                    'emotional_target' => [
                        'primary' => 'fear',
                        'secondary' => 'relief',
                        'intensity' => 0.7,
                    ],
                ],
                'recommendations' => [
                    'hooks' => [
                        ['hook_text' => '40 hours to 4 hours', 'hook_archetype' => 'compression', 'score' => 0.8],
                    ],
                    'angles' => [
                        ['label' => 'AI-driven SEO automation', 'score' => 0.7],
                    ],
                ],
            ],
            snapshot: []
        );
        $constraints = new Constraints(280, 'disallow', 'professional');
        $prompt = $composer->composePostGeneration($context, $constraints, 'Write a post about SEO');

        $this->assertStringContainsString('CREATIVE_INTELLIGENCE:', $prompt->system);
        $this->assertStringContainsString('User intent wins.', $prompt->system);
        $this->assertStringContainsString('Recommended hooks', $prompt->system);
    }
}
