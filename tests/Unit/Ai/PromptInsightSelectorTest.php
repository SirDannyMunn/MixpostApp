<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Generation\Steps\PromptInsightSelector;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class PromptInsightSelectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('ai.prompt_isa', [
            'enabled' => true,
            'max_insights' => 3,
            'max_chunk_chars' => 600,
            'min_keyword_hits' => 1,
            'task_keywords_max' => 12,
            'drop_if_contains' => ['###', '##', '```'],
            'strip_markdown' => true,
            'stopwords' => ['the','a','and','to','of','for','in','on','at','by','with','is','are','was','were','it','this','that'],
        ]);
    }

    public function test_drops_headings()
    {
        $sel = new PromptInsightSelector();
        $task = 'Create a post about Google SEO penalties for AI content.';
        $chunks = [ ['chunk_text' => "### Heading\nSome content about something else."] ];
        $res = $sel->buildInsights($task, 'educational', $chunks, []);
        $this->assertSame(0, $res['debug']['kept']);
        $this->assertSame(1, $res['debug']['dropped']['contains_heading']);
    }

    public function test_drops_off_task_chunk()
    {
        $sel = new PromptInsightSelector();
        $task = 'Google punishes AI content that is spammy and unhelpful.';
        $chunks = [
            ['chunk_text' => 'Our Autonomous R&D initiative uses multi-agent systems to discover product-market fit in life sciences.'],
        ];
        $res = $sel->buildInsights($task, 'educational', $chunks, []);
        $this->assertSame(0, $res['debug']['kept']);
        $this->assertGreaterThanOrEqual(1, $res['debug']['dropped']['low_keyword_hits']);
    }

    public function test_caps_insights_to_three()
    {
        $sel = new PromptInsightSelector();
        $task = 'Write about Google SEO rankings and AI content best practices.';
        $chunks = [];
        for ($i = 0; $i < 10; $i++) {
            $chunks[] = ['chunk_text' => "AI content quality affects search rankings and Helpful Content signals {$i}."];
        }
        $res = $sel->buildInsights($task, 'educational', $chunks, []);
        $this->assertCount(3, $res['bullets']);
        $this->assertSame(3, $res['debug']['kept']);
    }

    public function test_no_truncation_artifacts()
    {
        $sel = new PromptInsightSelector();
        $task = 'Explain how Google rewards helpful content and demotes spam.';
        $long = str_repeat('Helpful content improves rankings. ', 20) . 'Final sentence ends cleanly! Extra.';
        $chunks = [ ['chunk_text' => $long] ];
        $res = $sel->buildInsights($task, 'educational', $chunks, []);
        $this->assertSame(1, $res['debug']['kept']);
        $bullet = $res['bullets'][0] ?? '';
        $this->assertStringNotContainsString('...', $bullet);
        $this->assertMatchesRegularExpression('/^[^-]-\s+.+[\.!]$/', $bullet);
    }

    public function test_vip_still_gated_headings_dropped()
    {
        $sel = new PromptInsightSelector();
        $task = 'Discuss Google and SEO ranking signals.';
        $vip = [ ['chunk_text' => '## VIP Heading\nAI content policy summary.'] ];
        $res = $sel->buildInsights($task, 'educational', [], $vip);
        $this->assertSame(0, $res['debug']['kept']);
        $this->assertSame(1, $res['debug']['dropped']['contains_heading']);
    }
}

