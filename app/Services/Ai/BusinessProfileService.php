<?php

namespace App\Services\Ai;

use App\Models\Organization;

class BusinessProfileService
{
    public static function resolveForOrg(string $orgId): array
    {
        try {
            $org = Organization::findOrFail($orgId);
        } catch (\Throwable) {
            return [
                'snapshot' => self::fallbackSnapshot(),
                'version' => (string) config('business_context.snapshot_version', 'v1'),
                'used' => false,
                'retrieval_level' => 'shallow',
                'context' => 'business profile unavailable',
            ];
        }

        try {
            $snapshot = BusinessProfileDistiller::ensureSnapshot($org);
        } catch (\Throwable) {
            $snapshot = self::fallbackSnapshot();
        }

        $version = (string) ($snapshot['snapshot_version'] ?? config('business_context.snapshot_version', 'v1'));
        $context = self::formatSnapshotAsContext($snapshot);

        return [
            'snapshot' => $snapshot,
            'version' => $version,
            'used' => true,
            'retrieval_level' => 'shallow',
            'context' => $context,
        ];
    }

    public static function formatSnapshotAsContext(array $snapshot, array $opts = []): string
    {
        $includeFacts = (bool) ($opts['include_facts'] ?? true);
        $includePositioning = (bool) ($opts['include_positioning'] ?? true);
        $includeBeliefs = (bool) ($opts['include_beliefs'] ?? true);
        $includeProof = (bool) ($opts['include_proof'] ?? true);

        $lines = [];
        if (!empty($snapshot['summary'])) { $lines[] = 'SUMMARY: ' . (string) $snapshot['summary']; }
        if (!empty($snapshot['audience_summary'])) { $lines[] = 'AUDIENCE: ' . (string) $snapshot['audience_summary']; }
        if (!empty($snapshot['offer_summary'])) { $lines[] = 'OFFER: ' . (string) $snapshot['offer_summary']; }
        if (!empty($snapshot['tone_signature'])) {
            $ts = (array) $snapshot['tone_signature'];
            $toneBits = [];
            if (!empty($ts['formality'])) { $toneBits[] = 'formality=' . $ts['formality']; }
            if (!empty($ts['energy'])) { $toneBits[] = 'energy=' . $ts['energy']; }
            $toneBits[] = 'emoji=' . (isset($ts['emoji']) && $ts['emoji'] ? 'true' : 'false');
            $toneBits[] = 'slang=' . (isset($ts['slang']) && $ts['slang'] ? 'true' : 'false');
            $constraints = array_values(array_filter(array_map('strval', (array) ($ts['constraints'] ?? []))));
            if (!empty($constraints)) { $toneBits[] = 'constraints=[' . implode('; ', $constraints) . ']'; }
            $lines[] = 'TONE: ' . implode(', ', $toneBits);
        }
        if ($includePositioning && !empty($snapshot['positioning'])) {
            $lines[] = 'POSITIONING: ' . implode('; ', array_map('strval', (array) $snapshot['positioning']));
        }
        if ($includeBeliefs && !empty($snapshot['key_beliefs'])) {
            $lines[] = 'BELIEFS: ' . implode('; ', array_map('strval', (array) $snapshot['key_beliefs']));
        }
        if ($includeProof && !empty($snapshot['proof_points'])) {
            $lines[] = 'PROOF: ' . implode('; ', array_map('strval', (array) $snapshot['proof_points']));
        }
        if ($includeFacts && !empty($snapshot['facts'])) {
            $lines[] = 'FACTS: ' . implode('; ', array_map('strval', (array) $snapshot['facts']));
        }

        $text = implode("\n", $lines);

        // Simple token cap from config; approximate 1 token ~ 4 chars
        $cap = (int) config('business_context.token_cap', 600);
        $approxTokens = (int) ceil(mb_strlen($text) / 4);
        if ($approxTokens > $cap) {
            $maxChars = max(1, $cap * 4);
            $text = mb_substr($text, 0, $maxChars);
        }
        return $text;
    }

    private static function fallbackSnapshot(): array
    {
        return [
            'snapshot_version' => (string) config('business_context.snapshot_version', 'v1'),
            'summary' => 'business profile unavailable',
            'audience_summary' => '',
            'offer_summary' => '',
            'tone_signature' => [
                'formality' => 'neutral', 'energy' => 'medium', 'emoji' => true, 'slang' => false, 'constraints' => []
            ],
            'positioning' => [],
            'key_beliefs' => [],
            'proof_points' => [],
            'safe_examples' => [],
            'bad_examples' => [],
            'facts' => [],
        ];
    }
}

