<?php

use App\Services\Ai\Generation\DTO\Constraints;
use App\Services\Ai\Generation\Steps\PromptComposer;
use App\Services\Ai\GenerationContext;
use PHPUnit\Framework\TestCase;

final class PromptComposerTest extends TestCase
{
    public function test_it_outputs_plain_text_only(): void
    {
        $composer = new PromptComposer();
        $context = new GenerationContext(
            voice: null,
            template: (object) [ 'template_data' => [ 'structure' => [
                ['section' => 'Hook'], ['section' => 'Context'], ['section' => 'Lesson'], ['section' => 'Value Points'], ['section' => 'CTA'],
            ]]],
            chunks: [
                ['id' => '1', 'chunk_text' => 'AI-written generic content is suppressed. Be original.', 'score' => 0.9],
                ['id' => '2', 'chunk_text' => 'Opinionated writing builds authority over time.', 'score' => 0.8],
            ],
            facts: [ ['id' => 'f1', 'text' => 'Target: SaaS founders and solo operators.'] ],
            swipes: [ ['id' => 's1', 'cta_type' => 'discussion'] ],
            user_context: "SUMMARY: Help founders avoid generic AI tone. OFFER: Authority-driven content.",
            businessSummary: null,
            options: [],
            snapshot: []
        );
        $constraints = new Constraints(280, 'disallow', 'professional');
        $prompt = $composer->composePostGeneration($context, $constraints, 'Write about avoiding generic AI content');

        $system = $prompt->system;
        $user = $prompt->user;

        // Structural checks: no JSON braces/brackets
        $this->assertDoesNotMatchRegularExpression('/[{}\[\]]/', $system, 'System contains JSON-like braces/brackets');
        $this->assertDoesNotMatchRegularExpression('/[{}\[\]]/', $user, 'User contains JSON-like braces/brackets');

        // No internal labels
        $forbidden = ['KNOWLEDGE:', 'TEMPLATE_DATA:', 'SWIPE_STRUCTURES:', 'USER_CONTEXT:', '"id":', '"score":', '"confidence":'];
        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString($needle, $system . $user, "Found forbidden token {$needle}");
        }

        // Semantic sanity: should include human-readable sections
        $this->assertStringContainsString('Follow this structure strictly:', $user);
        $this->assertStringContainsString('Relevant insights to consider:', $user);
    }
}
