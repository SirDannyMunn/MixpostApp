<?php

namespace App\Services\Ai;

class ChunkKindResolver
{
    public static function fromRole(?string $role): string
    {
        $role = trim((string) $role);
        return match ($role) {
            'definition', 'metric', 'causal_claim' => 'fact',
            'belief_high', 'belief_medium', 'strategic_claim', 'heuristic' => 'angle',
            'example' => 'example',
            'quote' => 'quote',
            default => 'fact',
        };
    }

    public static function resolveKind(array|object $chunk): string
    {
        if (is_array($chunk)) {
            $kind = trim((string) ($chunk['chunk_kind'] ?? ''));
            if ($kind !== '') { return $kind; }
            return self::fromRole($chunk['chunk_role'] ?? null);
        }
        if (is_object($chunk)) {
            $kind = trim((string) ($chunk->chunk_kind ?? ''));
            if ($kind !== '') { return $kind; }
            return self::fromRole($chunk->chunk_role ?? null);
        }
        return 'fact';
    }
}
