<?php

namespace App\Services\Ai;

use App\Models\Organization;

class BusinessProfileDistiller
{
    public static function ensureSnapshot(Organization $organization): array
    {
        $settings = (array) ($organization->settings ?? []);
        $snapshot = (array) ($settings['business_profile_snapshot'] ?? []);
        $targetVersion = (string) config('business_context.snapshot_version', 'v1');

        if (empty($snapshot) || (($snapshot['snapshot_version'] ?? null) !== $targetVersion)) {
            $snapshot = self::build($organization);
            $settings['business_profile_snapshot'] = $snapshot;
            $organization->settings = $settings;
            // Do not let errors bubble up to controllers
            try { $organization->save(); } catch (\Throwable) {}
        }

        return $snapshot;
    }

    public static function build(Organization $organization): array
    {
        $s = $organization->settings_with_defaults;

        $summary = trim((string) ($s['core_business_context']['business_description'] ?? ''));
        if ($summary === '') { $summary = 'business profile unavailable'; }

        $audRole = trim((string) ($s['core_business_context']['primary_audience']['role'] ?? ''));
        $audInd = trim((string) ($s['core_business_context']['primary_audience']['industry'] ?? ''));
        $audSoph = trim((string) ($s['core_business_context']['primary_audience']['sophistication_level'] ?? ''));
        $audParts = array_values(array_filter([$audRole, $audInd, $audSoph]));
        $audienceSummary = !empty($audParts) ? implode(' / ', $audParts) : 'general audience';

        $valueProp = trim((string) ($s['positioning_differentiation']['primary_value_proposition'] ?? ''));
        $diffs = (array) ($s['positioning_differentiation']['top_differentiators'] ?? []);
        $offerSummary = $valueProp !== '' ? $valueProp : (empty($diffs) ? '' : ('Key differentiators: ' . implode('; ', array_map('strval', $diffs))));

        $formal = (int) ($s['brand_voice_tone']['tone_formal_casual'] ?? 5);
        $bold = (int) ($s['brand_voice_tone']['tone_bold_safe'] ?? 5);
        $playful = (int) ($s['brand_voice_tone']['tone_playful_serious'] ?? 5);
        $tone = [
            'formality' => $formal <= 3 ? 'formal' : ($formal >= 7 ? 'casual' : 'neutral'),
            'energy' => $bold >= 7 ? 'high' : ($bold <= 3 ? 'low' : 'medium'),
            'emoji' => (bool) (($s['brand_voice_tone']['allowed_language']['emojis'] ?? true)),
            'slang' => (bool) (($s['brand_voice_tone']['allowed_language']['slang'] ?? false)),
            'constraints' => array_values(array_filter(array_map('strval', (array) ($s['constraints_rules']['hard_constraints'] ?? [])))),
        ];

        $positioning = array_values(array_filter(array_map('strval', (array) ($s['positioning_differentiation']['top_differentiators'] ?? []))));
        if ($why = trim((string) ($s['positioning_differentiation']['why_we_win'] ?? ''))) {
            $positioning[] = $why;
        }

        $beliefs = array_values(array_filter(array_map('strval', (array) ($s['audience_psychology']['buying_emotions'] ?? []))));
        $proof = array_values(array_filter(array_map('strval', (array) ($s['audience_psychology']['common_objections'] ?? []))));

        $good = array_values(array_filter((array) ($s['advanced_settings']['examples_of_good_content'] ?? [])));
        $bad = array_values(array_filter((array) ($s['advanced_settings']['examples_of_bad_content'] ?? [])));
        $safeExamples = array_map(fn($t) => ['short' => mb_substr((string) $t, 0, 200)], $good);
        $badExamples = array_map(fn($t) => ['short' => mb_substr((string) $t, 0, 200)], $bad);

        $facts = array_values(array_filter(array_map('strval', (array) ($s['advanced_settings']['seo_keywords'] ?? []))));

        return [
            'snapshot_version' => (string) config('business_context.snapshot_version', 'v1'),
            'summary' => $summary,
            'audience_summary' => $audienceSummary,
            'offer_summary' => $offerSummary,
            'tone_signature' => $tone,
            'positioning' => $positioning,
            'key_beliefs' => $beliefs,
            'proof_points' => $proof,
            'safe_examples' => $safeExamples,
            'bad_examples' => $badExamples,
            'facts' => $facts,
        ];
    }
}

