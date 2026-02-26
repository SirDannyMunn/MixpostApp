<?php

namespace App\Services\Chunking;

use App\Models\KnowledgeItem;
use App\Services\Chunking\Strategies\ChunkingStrategy;
use App\Services\Chunking\Strategies\ListToDataPointsStrategy;
use App\Services\Chunking\Strategies\ShortPostClaimStrategy;
use App\Services\Chunking\Strategies\FallbackSentenceStrategy;
use App\Services\Ingestion\KnowledgeCompiler;

class ChunkingStrategyRouter
{
    public function selectStrategy(string $format, int $tokenCount): ChunkingStrategy
    {
        return match ($format) {
            'numeric_list' => new ListToDataPointsStrategy(),
            'bullet_list' => $tokenCount < 60 
                ? new ShortPostClaimStrategy() 
                : new FallbackSentenceStrategy(),
            'short_post', 'promo_cta' => new ShortPostClaimStrategy(),
            'plain_text' => new FallbackSentenceStrategy(), // Will be used as fallback when LLM fails
            default => new FallbackSentenceStrategy(),
        };
    }

    public function shouldUseLLMExtraction(string $format, int $tokenCount): bool
    {
        // Use LLM extraction for plain text that's not too short or too long
        return $format === 'plain_text' && $tokenCount >= 60 && $tokenCount <= 800;
    }
}
