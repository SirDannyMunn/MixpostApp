<?php

namespace App\Services\Ai;

use App\Models\GenerationQualityReport;

class QualityReportService
{
    public function store(string $orgId, string $userId, string $intent, float $overall, array $scores, ?string $snapshotId = null, ?string $generatedPostId = null): void
    {
        GenerationQualityReport::create([
            'organization_id' => $orgId,
            'user_id' => $userId,
            'generated_post_id' => $generatedPostId ?: null,
            'snapshot_id' => $snapshotId ?: null,
            'intent' => $intent,
            'overall_score' => $overall,
            'scores' => $scores,
            'created_at' => now(),
        ]);
    }
}

