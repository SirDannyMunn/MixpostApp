<?php

namespace App\Services\Ai;

class ContextAssembler
{
    /**
     * Assemble and sanitize generation context.
     * - Enforces counts/budgets with token-aware pruning
     * - Removes any raw swipe text
     * - Records reference IDs for traceability
     */
    public function build(array $parts): GenerationContext
    {
        $options = (array) ($parts['options'] ?? []);

        // Light caps to bound context size
        $maxChunks = (int) ($options['max_chunks'] ?? 5);
        $maxFacts = (int) ($options['max_facts'] ?? 8);
        $maxSwipes = (int) ($options['max_swipes'] ?? 2);

        // Merge VIP-first items ahead of regular items
        $vipChunks = (array) ($parts['vip_chunks'] ?? []);
        $vipFacts = (array) ($parts['vip_facts'] ?? []);
        $vipSwipes = (array) ($parts['vip_swipes'] ?? []);

        // Prefer near-match chunks by treating them as VIP for budget allocation
        $allChunks = (array) ($parts['chunks'] ?? []);
        $nearMatches = array_values(array_filter($allChunks, function ($c) {
            return is_array($c) && !empty($c['__near_match']);
        }));
        $nonNear = array_values(array_filter($allChunks, function ($c) {
            return !(is_array($c) && !empty($c['__near_match']));
        }));

        // Order for presentation only; actual inclusion is governed in pruneToBudget via VIP-first
        $chunks = array_slice(array_merge($vipChunks, $nearMatches, $nonNear), 0, $maxChunks);
        $facts = array_slice(array_merge($vipFacts, (array) ($parts['facts'] ?? [])), 0, $maxFacts);
        $swipes = array_slice(array_merge($vipSwipes, (array) ($parts['swipes'] ?? [])), 0, $maxSwipes);

        // Hard rule: never allow raw swipe text in context
        foreach ($swipes as &$s) {
            if (is_array($s)) {
                unset($s['raw_text']);
                unset($s['swipe_raw']);
            }
        }
        unset($s);

        // Token-aware pruning pass across chunks/facts/user_context
        $budget = (int) ($options['context_token_budget'] ?? (int) config('prompting.context_token_budget', 1800));
        // Elevate near-matches to VIP in budgeting to ensure inclusion
        $vipChunksForBudget = array_merge($vipChunks, $nearMatches);

        [$chunks, $facts, $userContextPruned, $debug] = $this->pruneToBudget(
            // Exclude near-matches here to avoid duplicates (they are passed as VIP)
            $nonNear,
            $facts,
            (string) ($parts['user_context'] ?? ''),
            (string) ($parts['business_context'] ?? ''),
            $swipes,
            (array) ($parts['template']?->template_data ?? []),
            $vipChunksForBudget,
            $vipFacts
        );

        $snapshot = [
            'template_id' => $parts['template']?->id ?? null,
            // Include voice profile used for this context (if any)
            'voice_profile_id' => $parts['voice']?->id ?? null,
            'chunk_ids' => array_values(array_filter(array_map(fn($c) => $c['id'] ?? null, $chunks))),
            'fact_ids' => array_values(array_filter(array_map(fn($f) => $f['id'] ?? null, $facts))),
            'swipe_ids' => array_values(array_filter(array_map(fn($s) => $s['id'] ?? null, $swipes))),
            'reference_ids' => array_values((array) ($parts['reference_ids'] ?? [])),
            'creative_intelligence' => $parts['creative_intelligence'] ?? null,
        ];

        return new GenerationContext(
            voice: $parts['voice'] ?? null,
            template: $parts['template'] ?? null,
            chunks: $chunks,
            vip_chunks: $vipChunksForBudget,
            enrichment_chunks: (array) ($parts['enrichment_chunks'] ?? []),
            facts: $facts,
            swipes: $swipes,
            user_context: $userContextPruned,
            businessSummary: isset($parts['business_summary']) && $parts['business_summary'] !== ''
                ? (string) $parts['business_summary']
                : null,
            options: array_merge($options, ['context_token_budget' => $budget]),
            creative_intelligence: isset($parts['creative_intelligence']) && is_array($parts['creative_intelligence'])
                ? $parts['creative_intelligence']
                : null,
            snapshot: $snapshot,
            debug: array_merge($debug, ['creative_intelligence' => $parts['creative_intelligence'] ?? null]),
            decision_trace: (array) ($parts['decision_trace'] ?? []),
            prompt_mutations: (array) ($parts['prompt_mutations'] ?? []),
            ci_rejections: (array) ($parts['ci_rejections'] ?? []),
        );
    }

    private function pruneToBudget(array $chunks, array $facts, string $userContext, string $businessContext, array $swipes, array $templateData, array $vipChunks = [], array $vipFacts = []): array
    {
        $budget = (int) config('prompting.context_token_budget', 1800);
        $used = 0;
        // Reserve small fixed overhead for template and swipe structure JSON
        $tplTok = $this->estimateTokens(json_encode($templateData) ?: '');
        $swpTok = $this->estimateTokens(json_encode($swipes) ?: '');
        $used += $tplTok + $swpTok;

        // VIP-first inclusion: deduct budget for VIP chunks/facts up-front
        $outChunks = [];
        $outFacts = [];
        $outUser = '';
        $usage = [
            'chunks_tokens' => 0,
            'facts_tokens' => 0,
            'user_tokens' => 0,
            'business_context_tokens' => 0,
            'template_tokens' => $tplTok,
            'swipe_tokens' => $swpTok,
            'total' => $tplTok + $swpTok
        ];
        $usedCounts = ['chunks' => 0, 'facts' => 0, 'user' => 0];

        foreach ($vipChunks as $c) {
            $text = (string) ($c['chunk_text'] ?? ($c['text'] ?? ''));
            $tok = $this->estimateTokens($text);
            if ($used + $tok > $budget) { continue; }
            $used += $tok;
            $outChunks[] = $c;
            $usage['chunks_tokens'] += $tok;
            $usedCounts['chunks']++;
        }
        foreach ($vipFacts as $f) {
            $text = (string) ($f['text'] ?? '');
            $tok = $this->estimateTokens($text);
            if ($used + $tok > $budget) { continue; }
            $used += $tok;
            $outFacts[] = $f;
            $usage['facts_tokens'] += $tok;
            $usedCounts['facts']++;
        }
        $usage['total'] = $used;

        // Business context is VIP and never pruned. It is included first.
        $businessContext = (string) $businessContext;
        $outBusiness = '';
        if ($businessContext !== '') {
            $tok = $this->estimateTokens($businessContext);
            // Include regardless of budget; allow overage here and let other items prune
            $outBusiness = $businessContext;
            $used += $tok;
            $usage['business_context_tokens'] += $tok;
            $usage['total'] = $used;
        }

        // Prepare scored items: prefer higher confidence/score density
        $items = [];
        $providedCounts = ['chunks' => count($chunks), 'facts' => count($facts), 'user' => $userContext !== '' ? 1 : 0];
        foreach ($chunks as $c) {
            $text = (string) ($c['chunk_text'] ?? '');
            $tok = $this->estimateTokens($text);
            $score = (float) ($c['score'] ?? 0.5);
            $density = $tok > 0 ? ($score / $tok) : $score;
            $items[] = ['type' => 'chunk', 'payload' => $c, 'text' => $text, 'tok' => $tok, 'density' => $density];
        }
        foreach ($facts as $f) {
            $text = (string) ($f['text'] ?? '');
            $tok = $this->estimateTokens($text);
            $score = (float) ($f['confidence'] ?? 0.5);
            $density = $tok > 0 ? ($score / $tok) : $score;
            $items[] = ['type' => 'fact', 'payload' => $f, 'text' => $text, 'tok' => $tok, 'density' => $density];
        }
        if ($userContext !== '') {
            $tok = $this->estimateTokens($userContext);
            // Treat user context as medium priority
            $items[] = ['type' => 'user', 'payload' => null, 'text' => $userContext, 'tok' => $tok, 'density' => 0.4 / max(1, $tok)];
        }

        usort($items, fn($a, $b) => $a['density'] < $b['density'] ? 1 : ($a['density'] > $b['density'] ? -1 : 0));
        foreach ($items as $it) {
            if ($used + $it['tok'] > $budget) continue;
            $used += $it['tok'];
            if ($it['type'] === 'chunk') { $outChunks[] = $it['payload']; $usage['chunks_tokens'] += $it['tok']; $usedCounts['chunks']++; }
            elseif ($it['type'] === 'fact') { $outFacts[] = $it['payload']; $usage['facts_tokens'] += $it['tok']; $usedCounts['facts']++; }
            elseif ($it['type'] === 'user') { $outUser = $it['text']; $usage['user_tokens'] += $it['tok']; $usedCounts['user'] = 1; }
            $usage['total'] = $used;
        }

        // Prepend business context to user context so it is clearly present
        if ($outBusiness !== '') {
            $outUser = ($outUser !== '' ? ($outBusiness . "\n\n" . $outUser) : $outBusiness);
        }

        $debug = [
            'usage' => $usage,
            'provided_counts' => $providedCounts,
            'used_counts' => $usedCounts,
            'pruned_counts' => [
                'chunks' => max(0, $providedCounts['chunks'] - $usedCounts['chunks']),
                'facts' => max(0, $providedCounts['facts'] - $usedCounts['facts']),
                'user' => max(0, $providedCounts['user'] - $usedCounts['user']),
            ],
        ];

        return [$outChunks, $outFacts, $outUser, $debug];
    }

    private function estimateTokens(string $text): int
    {
        // Rough heuristic: 1 token ~ 4 characters (English)
        $len = mb_strlen($text);
        return (int) max(1, ceil($len / 4));
    }
}
