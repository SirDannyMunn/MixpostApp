<?php

namespace App\Services\Ai;

class GenerationContext
{
    public function __construct(
        public readonly ?object $voice,
        public readonly ?object $template,
        public readonly array $chunks,
        public readonly array $vip_chunks = [],
        public readonly array $enrichment_chunks = [],
        public readonly array $facts = [],
        public readonly array $swipes = [],
        public readonly ?string $user_context = null,
        public readonly ?string $businessSummary = null,
        public readonly array $options = [],
        public readonly ?array $creative_intelligence = null,
        protected readonly array $snapshot = [],
        protected readonly array $debug = [],
        protected readonly array $decision_trace = [],
        protected readonly array $prompt_mutations = [],
        protected readonly array $ci_rejections = [],
    ) {}

    public function snapshotIds(): array
    {
        return $this->snapshot;
    }

    public function debug(): array
    {
        return $this->debug;
    }

    public function decisionTrace(): array
    {
        return $this->decision_trace;
    }

    public function promptMutations(): array
    {
        return $this->prompt_mutations;
    }

    public function ciRejections(): array
    {
        return $this->ci_rejections;
    }

    /**
     * Return final token usage metrics derived during assembly/pruning.
     */
    public function calculateTokenUsage(): array
    {
        return (array) ($this->debug['usage'] ?? []);
    }
}
