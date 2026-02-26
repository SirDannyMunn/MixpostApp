<?php

use App\Services\Ai\Generation\DTO\Constraints;
use App\Services\Ai\Generation\Steps\PromptComposer;
use App\Services\Ai\GenerationContext;
use PHPUnit\Framework\TestCase;

final class PromptComposerContextFixTest extends TestCase
{
    private function baseContext(array $overrides = []): GenerationContext
    {
        $defaults = [
            'voice' => null,
            'template' => (object) [ 'template_data' => [ 'structure' => [
                ['section' => 'Hook'], ['section' => 'Context'], ['section' => 'Lesson'], ['section' => 'Value Points'], ['section' => 'CTA'],
            ]]],
            'chunks' => [],
            'facts' => [],
            'swipes' => [],
            'user_context' => null,
            'options' => [],
            'snapshot' => [],
        ];
        $data = array_replace($defaults, $overrides);
        return new GenerationContext(
            voice: $data['voice'],
            template: $data['template'],
            chunks: $data['chunks'],
            facts: $data['facts'],
            swipes: $data['swipes'],
            user_context: $data['user_context'],
            businessSummary: $data['business_summary'] ?? null,
            options: $data['options'],
            snapshot: $data['snapshot'],
        );
    }

    public function test_context_labels_eliminated_and_distilled(): void
    {
        $composer = new PromptComposer();
        $ctx = $this->baseContext([
            'user_context' => "SUMMARY: Velocity turns structured inputs into high-signal posts.\nAUDIENCE: SaaS founders / Solo operators\nPOSITIONING: Strategy-first clarity.\nBELIEFS: Keep it simple.",
            'business_summary' => 'Velocity turns structured inputs into high-signal posts for SaaS founders and solo operators.',
        ]);
        $constraints = new Constraints(280, 'disallow', 'professional');
        $prompt = $composer->composePostGeneration($ctx, $constraints, 'Write about avoiding generic AI content');

        $out = $prompt->system . "\n" . $prompt->user;
        $this->assertStringNotContainsString('SUMMARY:', $out);
        $this->assertStringNotContainsString('AUDIENCE:', $out);
        $this->assertStringNotContainsString('POSITIONING:', $out);
        $this->assertStringNotContainsString('BELIEFS:', $out);

        $this->assertStringContainsString("Context:\nAudience:", $prompt->user);
        $this->assertMatchesRegularExpression('/Context:\nAudience: .*\nPositioning: .*\nProduct context: .*\nGoal: .*/', $prompt->user);
    }

    public function test_no_truncation_artifacts_and_markdown_stripped(): void
    {
        $composer = new PromptComposer();
        $chunk = [
            'id' => 'x1',
            'chunk_text' => "### Big Heading\n- Autonomous scheduling increases productivity over time by reducing context switching and decision fatigue. Additional discussion follows with more detail.",
            'score' => 0.9,
        ];
        $ctx = $this->baseContext([
            'chunks' => [$chunk],
        ]);
        $constraints = new Constraints(280, 'disallow', 'professional');
        $prompt = $composer->composePostGeneration($ctx, $constraints, 'Test summarization');
        $user = $prompt->user;

        $this->assertStringNotContainsString('###', $user);
        $this->assertStringContainsString('autonomous scheduling', strtolower($user));
    }

    public function test_caps_applied_after_summarization(): void
    {
        $composer = new PromptComposer();
        $long = str_repeat('Insightful content that avoids generic phrasing. ', 20);
        $ctx = $this->baseContext([
            'chunks' => [['id' => 'c1', 'chunk_text' => $long, 'score' => 0.8]],
        ]);
        $constraints = new Constraints(280, 'disallow', 'professional');
        $prompt = $composer->composePostGeneration($ctx, $constraints, 'Test caps');
        $user = $prompt->user;
        if (preg_match('/Relevant insights to consider:\n([\s\S]+)/', $user, $m)) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $m[1]))));
            foreach ($lines as $ln) {
                if (!str_starts_with($ln, '- ')) { continue; }
                $this->assertLessThanOrEqual(244, strlen($ln)); // bullet + space + content cap
            }
        } else {
            $this->fail('Insights section not found');
        }
    }
}
