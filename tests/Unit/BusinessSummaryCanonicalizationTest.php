<?php

use App\Services\Ai\Generation\DTO\Constraints;
use App\Services\Ai\Generation\Steps\PromptComposer;
use App\Services\Ai\GenerationContext;
use PHPUnit\Framework\TestCase;

final class BusinessSummaryCanonicalizationTest extends TestCase
{
    private function ctxWithSummary(string $summary, ?string $userContext = null): GenerationContext
    {
        return new GenerationContext(
            voice: null,
            template: (object) [ 'template_data' => [ 'structure' => [
                ['section' => 'Hook'], ['section' => 'Context'], ['section' => 'Lesson'], ['section' => 'Value Points'], ['section' => 'CTA'],
            ]]],
            chunks: [],
            facts: [],
            swipes: [],
            user_context: $userContext,
            businessSummary: $summary,
            options: [],
            snapshot: []
        );
    }

    public function test_canonical_summary_is_used(): void
    {
        $composer = new PromptComposer();
        $ctx = $this->ctxWithSummary('SENTENCE A.', "SUMMARY: ignored.\nOFFER: SENTENCE B.");
        $constraints = new Constraints(280, 'disallow', 'professional');
        $prompt = $composer->composePostGeneration($ctx, $constraints, 'Topic');
        $this->assertStringContainsString("Product context: SENTENCE A.", $prompt->user);
    }

    public function test_fallback_not_present_omits_product_line(): void
    {
        $composer = new PromptComposer();
        $ctx = $this->ctxWithSummary('', "SUMMARY: should not be used.");
        $constraints = new Constraints(280, 'disallow', 'professional');
        $prompt = $composer->composePostGeneration($ctx, $constraints, 'Topic');
        $this->assertStringNotContainsString('Product context:', $prompt->user);
    }

    public function test_no_concatenation_from_other_fields(): void
    {
        $composer = new PromptComposer();
        $userCtx = "SUMMARY: Not used.\nOFFER: Value X.\nPOSITIONING: Y.\nBELIEFS: Z.";
        $ctx = $this->ctxWithSummary('Canonical sentence only.', $userCtx);
        $constraints = new Constraints(280, 'disallow', 'professional');
        $prompt = $composer->composePostGeneration($ctx, $constraints, 'Topic');
        $user = $prompt->user;
        $this->assertStringContainsString('Product context: Canonical sentence only.', $user);
        $this->assertStringNotContainsString('OFFER:', $user);
        $this->assertStringNotContainsString('BELIEFS:', $user);
        $this->assertStringNotContainsString('PROOF:', $user);
        $this->assertStringNotContainsString('FACTS:', $user);
    }
}

