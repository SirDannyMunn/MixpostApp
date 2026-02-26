<?php

namespace App\Enums;

enum ResearchStage: string
{
    case DEEP_RESEARCH = 'deep_research';
    case ANGLE_HOOKS = 'angle_hooks';
    case TREND_DISCOVERY = 'trend_discovery';
    case SATURATION_OPPORTUNITY = 'saturation_opportunity';

    public static function fromString(string $value): ?self
    {
        return match (strtolower(trim($value))) {
            'deep_research' => self::DEEP_RESEARCH,
            'angle_hooks' => self::ANGLE_HOOKS,
            'trend_discovery' => self::TREND_DISCOVERY,
            'saturation_opportunity' => self::SATURATION_OPPORTUNITY,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::DEEP_RESEARCH => 'Deep Research',
            self::ANGLE_HOOKS => 'Angle & Hooks',
            self::TREND_DISCOVERY => 'Trend Discovery',
            self::SATURATION_OPPORTUNITY => 'Saturation & Opportunity',
        };
    }
}
