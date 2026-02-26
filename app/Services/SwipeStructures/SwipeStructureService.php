<?php

namespace App\Services\SwipeStructures;

use App\Models\SwipeStructure;

class SwipeStructureService
{
    public function createFromIngestionSource(array $attrs): SwipeStructure
    {
        $organizationId = (string) ($attrs['organization_id'] ?? '');
        if ($organizationId === '') {
            throw new \InvalidArgumentException('organization_id required');
        }

        $confidence = $this->clampConfidence($attrs['confidence'] ?? 50);

        return SwipeStructure::create([
            'organization_id' => $organizationId,
            'swipe_item_id' => $attrs['swipe_item_id'] ?? null,
            'title' => $attrs['title'] ?? null,
            'intent' => $attrs['intent'] ?? null,
            'funnel_stage' => $attrs['funnel_stage'] ?? null,
            'hook_type' => $attrs['hook_type'] ?? null,
            'cta_type' => $attrs['cta_type'] ?? 'none',
            'structure' => $attrs['structure'] ?? [],
            'language_signals' => $attrs['language_signals'] ?? null,
            'confidence' => $confidence,
            'is_ephemeral' => false,
            'origin' => 'ingestion_source',
            'created_by_user_id' => $attrs['created_by_user_id'] ?? null,
            'created_at' => $attrs['created_at'] ?? now(),
        ]);
    }

    public function createManual(string $organizationId, string $userId, array $data): SwipeStructure
    {
        $confidence = $this->clampConfidence($data['confidence'] ?? 50);

        return SwipeStructure::create([
            'organization_id' => $organizationId,
            'swipe_item_id' => null,
            'title' => $data['title'] ?? null,
            'intent' => $data['intent'] ?? null,
            'funnel_stage' => $data['funnel_stage'] ?? null,
            'hook_type' => $data['hook_type'] ?? null,
            'cta_type' => $data['cta_type'] ?? 'none',
            'structure' => $data['structure'] ?? [],
            'confidence' => $confidence,
            'is_ephemeral' => false,
            'origin' => 'manual',
            'created_by_user_id' => $userId,
            'created_at' => now(),
        ]);
    }

    public function update(SwipeStructure $structure, array $data): SwipeStructure
    {
        if (array_key_exists('title', $data)) {
            $structure->title = $data['title'];
        }
        if (array_key_exists('intent', $data)) {
            $structure->intent = $data['intent'];
        }
        if (array_key_exists('funnel_stage', $data)) {
            $structure->funnel_stage = $data['funnel_stage'];
        }
        if (array_key_exists('hook_type', $data)) {
            $structure->hook_type = $data['hook_type'];
        }
        if (array_key_exists('cta_type', $data)) {
            $structure->cta_type = $data['cta_type'];
        }
        if (array_key_exists('structure', $data)) {
            $structure->structure = $data['structure'];
        }
        if (array_key_exists('confidence', $data)) {
            $structure->confidence = $this->clampConfidence($data['confidence']);
        }

        $structure->save();
        return $structure;
    }

    public function softDelete(SwipeStructure $structure): void
    {
        $origin = (string) ($structure->origin ?? '');
        if ($origin === 'system_seed') {
            throw new \RuntimeException('System-seeded structures cannot be deleted');
        }

        $structure->deleted_at = now();
        $structure->save();
    }

    private function clampConfidence(mixed $value): int
    {
        $n = (int) $value;
        return max(0, min(100, $n));
    }
}
