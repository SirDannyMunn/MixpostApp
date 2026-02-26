<?php

namespace App\Services\Ai\Research\DTO;

use App\Enums\ResearchStage;

class ResearchResult
{
    public function __construct(
        public readonly ResearchStage $stage,
        public readonly string $question,
        public readonly array $dominantClaims = [],
        public readonly array $pointsOfDisagreement = [],
        public readonly array $emergingAngles = [],
        public readonly array $saturatedAngles = [],
        public readonly array $sampleExcerpts = [],
        public readonly array $hooks = [],
        public readonly array $trends = [],
        public readonly array $saturationReport = [],
        public readonly ?string $snapshotId = null,
        public readonly array $metadata = [],
        public readonly array $debug = [],
    ) {}

    public function toReport(): array
    {
        return match ($this->stage) {
            ResearchStage::DEEP_RESEARCH => [
                'dominant_claims' => $this->dominantClaims,
                'points_of_disagreement' => $this->pointsOfDisagreement,
                'emerging_angles' => $this->emergingAngles,
                'saturated_angles' => $this->saturatedAngles,
                'example_excerpts' => $this->sampleExcerpts,
            ],
            ResearchStage::ANGLE_HOOKS => [
                'hooks' => $this->hooks,
            ],
            ResearchStage::TREND_DISCOVERY => [
                'query' => $this->question,
                'industry' => $this->metadata['industry'] ?? '',
                'trends' => $this->trends,
            ],
            ResearchStage::SATURATION_OPPORTUNITY => $this->saturationReport,
        };
    }

    public function toArray(): array
    {
        return [
            'stage' => $this->stage->value,
            'question' => $this->question,
            'report' => $this->toReport(),
            'snapshot_id' => $this->snapshotId,
            'metadata' => $this->metadata,
            'debug' => $this->debug,
        ];
    }
}
