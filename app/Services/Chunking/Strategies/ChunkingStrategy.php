<?php

namespace App\Services\Chunking\Strategies;

use App\Models\KnowledgeItem;

interface ChunkingStrategy
{
    public function generateChunks(KnowledgeItem $item, string $text): array;
}
