<?php

namespace App\Services\Voice;

class VoiceTraitsMerger
{
    private const FREQUENCY_THRESHOLD = 0.30; // Keep items appearing in ≥30% of batches
    private const MAX_LIST_LENGTH = 20;

    /**
     * Merge multiple batch trait objects into a single canonical v2 traits object.
     *
     * @param array $batchTraits Array of trait objects from multiple batches
     * @return array Canonical merged traits
     */
    public static function merge(array $batchTraits): array
    {
        if (empty($batchTraits)) {
            return VoiceTraitsValidator::getDefaultTraits();
        }

        if (count($batchTraits) === 1) {
            // Even with single batch, apply list caps for consistency
            $single = $batchTraits[0];
            foreach (['tone', 'style_signatures', 'do_not_do', 'must_do', 'keyword_bias', 'rhetorical_devices', 'reference_examples'] as $field) {
                if (isset($single[$field]) && is_array($single[$field]) && count($single[$field]) > self::MAX_LIST_LENGTH) {
                    $single[$field] = array_slice($single[$field], 0, self::MAX_LIST_LENGTH);
                }
            }
            return $single;
        }

        $merged = [
            'schema_version' => '2.0',
            'description' => self::mergeDescriptions($batchTraits),
            'tone' => self::mergeList($batchTraits, 'tone'),
            'persona' => self::mergeStrings($batchTraits, 'persona'),
            'formality' => self::mergeEnum($batchTraits, 'formality'),
            'sentence_length' => self::mergeEnum($batchTraits, 'sentence_length'),
            'paragraph_density' => self::mergeEnum($batchTraits, 'paragraph_density'),
            'pacing' => self::mergeEnum($batchTraits, 'pacing'),
            'emotional_intensity' => self::mergeEnum($batchTraits, 'emotional_intensity'),
            'format_rules' => self::mergeFormatRules($batchTraits),
            'persona_contract' => self::mergePersonaContract($batchTraits),
            'rhetorical_devices' => self::mergeList($batchTraits, 'rhetorical_devices'),
            'style_signatures' => self::mergeList($batchTraits, 'style_signatures'),
            'do_not_do' => self::mergeList($batchTraits, 'do_not_do'),
            'must_do' => self::mergeList($batchTraits, 'must_do'),
            'keyword_bias' => self::mergeList($batchTraits, 'keyword_bias'),
            'phrases' => self::mergePhrases($batchTraits),
            'structure_patterns' => self::mergeStructurePatterns($batchTraits),
            'reference_examples' => self::mergeList($batchTraits, 'reference_examples'),
            'safety' => self::mergeSafety($batchTraits),
        ];

        return $merged;
    }

    /**
     * Merge descriptions by taking the most common one.
     */
    private static function mergeDescriptions(array $batchTraits): string
    {
        $descriptions = array_column($batchTraits, 'description');
        $descriptions = array_filter($descriptions, fn($d) => !empty($d));
        
        if (empty($descriptions)) {
            return 'Voice profile';
        }

        $counts = array_count_values($descriptions);
        arsort($counts);
        return array_key_first($counts);
    }

    /**
     * Merge string fields by taking most common value.
     */
    private static function mergeStrings(array $batchTraits, string $key): ?string
    {
        $values = array_column($batchTraits, $key);
        $values = array_filter($values, fn($v) => $v !== null && $v !== '');
        
        if (empty($values)) {
            return null;
        }

        $counts = array_count_values($values);
        arsort($counts);
        return array_key_first($counts);
    }

    /**
     * Merge enum fields using majority vote. If tie, return null.
     */
    private static function mergeEnum(array $batchTraits, string $key): ?string
    {
        $values = array_column($batchTraits, $key);
        $values = array_filter($values, fn($v) => $v !== null);
        
        if (empty($values)) {
            return null;
        }

        $counts = array_count_values($values);
        arsort($counts);
        
        $topValues = array_keys($counts, max($counts));
        
        // If tie, return null
        if (count($topValues) > 1) {
            return null;
        }
        
        return $topValues[0];
    }

    /**
     * Merge list fields using frequency threshold.
     * Keep items that appear in >= FREQUENCY_THRESHOLD of batches.
     */
    private static function mergeList(array $batchTraits, string $key): array
    {
        $allItems = [];
        $batchCount = count($batchTraits);
        
        foreach ($batchTraits as $batch) {
            $items = $batch[$key] ?? [];
            if (!is_array($items)) {
                continue;
            }
            
            foreach ($items as $item) {
                if (!isset($allItems[$item])) {
                    $allItems[$item] = 0;
                }
                $allItems[$item]++;
            }
        }

        // Filter by frequency threshold
        $threshold = max(1, (int)ceil($batchCount * self::FREQUENCY_THRESHOLD));
        $filtered = array_keys(array_filter($allItems, fn($count) => $count >= $threshold));

        // Sort by frequency (descending) and cap length
        usort($filtered, fn($a, $b) => $allItems[$b] <=> $allItems[$a]);
        
        return array_slice($filtered, 0, self::MAX_LIST_LENGTH);
    }

    /**
     * Merge format_rules object.
     */
    private static function mergeFormatRules(array $batchTraits): array
    {
        $rules = [];
        
        foreach ($batchTraits as $batch) {
            if (isset($batch['format_rules']) && is_array($batch['format_rules'])) {
                foreach ($batch['format_rules'] as $ruleKey => $ruleValue) {
                    if (!isset($rules[$ruleKey])) {
                        $rules[$ruleKey] = [];
                    }
                    $rules[$ruleKey][] = $ruleValue;
                }
            }
        }

        $merged = [];
        foreach ($rules as $ruleKey => $values) {
            $merged[$ruleKey] = self::mergeEnumFromArray($values);
        }

        return $merged;
    }

    /**
     * Merge persona_contract object.
     */
    private static function mergePersonaContract(array $batchTraits): array
    {
        $contract = [
            'in_group' => [],
            'out_group' => [],
            'status_claims' => [],
            'exclusion_language' => [],
            'credibility_moves' => [],
        ];

        foreach (array_keys($contract) as $field) {
            $allItems = [];
            $batchCount = count($batchTraits);
            
            foreach ($batchTraits as $batch) {
                $items = $batch['persona_contract'][$field] ?? [];
                if (!is_array($items)) {
                    continue;
                }
                
                foreach ($items as $item) {
                    if (!isset($allItems[$item])) {
                        $allItems[$item] = 0;
                    }
                    $allItems[$item]++;
                }
            }

            $threshold = max(1, (int)ceil($batchCount * self::FREQUENCY_THRESHOLD));
            $filtered = array_keys(array_filter($allItems, fn($count) => $count >= $threshold));

            usort($filtered, fn($a, $b) => $allItems[$b] <=> $allItems[$a]);
            $contract[$field] = array_slice($filtered, 0, self::MAX_LIST_LENGTH);
        }

        return $contract;
    }

    /**
     * Merge phrases object.
     */
    private static function mergePhrases(array $batchTraits): array
    {
        $phrases = [
            'openers' => [],
            'closers' => [],
            'cta_phrases' => [],
            'rejection_phrases' => [],
        ];

        foreach (array_keys($phrases) as $field) {
            $allItems = [];
            $batchCount = count($batchTraits);
            
            foreach ($batchTraits as $batch) {
                $items = $batch['phrases'][$field] ?? [];
                if (!is_array($items)) {
                    continue;
                }
                
                foreach ($items as $item) {
                    if (!isset($allItems[$item])) {
                        $allItems[$item] = 0;
                    }
                    $allItems[$item]++;
                }
            }

            $threshold = max(1, (int)ceil($batchCount * self::FREQUENCY_THRESHOLD));
            $filtered = array_keys(array_filter($allItems, fn($count) => $count >= $threshold));

            usort($filtered, fn($a, $b) => $allItems[$b] <=> $allItems[$a]);
            $phrases[$field] = array_slice($filtered, 0, self::MAX_LIST_LENGTH);
        }

        return $phrases;
    }

    /**
     * Merge structure_patterns object.
     */
    private static function mergeStructurePatterns(array $batchTraits): array
    {
        $commonSections = [];
        $ctaPresence = [];
        $offerFormat = [];
        $batchCount = count($batchTraits);

        foreach ($batchTraits as $batch) {
            $sp = $batch['structure_patterns'] ?? [];
            
            if (isset($sp['common_sections']) && is_array($sp['common_sections'])) {
                foreach ($sp['common_sections'] as $section) {
                    if (!isset($commonSections[$section])) {
                        $commonSections[$section] = 0;
                    }
                    $commonSections[$section]++;
                }
            }
            
            if (isset($sp['cta_presence'])) {
                $ctaPresence[] = $sp['cta_presence'];
            }
            
            if (isset($sp['offer_format'])) {
                $offerFormat[] = $sp['offer_format'];
            }
        }

        $threshold = max(1, (int)ceil($batchCount * self::FREQUENCY_THRESHOLD));
        $filteredSections = array_keys(array_filter($commonSections, fn($count) => $count >= $threshold));
        usort($filteredSections, fn($a, $b) => $commonSections[$b] <=> $commonSections[$a]);

        return [
            'common_sections' => array_slice($filteredSections, 0, self::MAX_LIST_LENGTH),
            'cta_presence' => self::mergeEnumFromArray($ctaPresence),
            'offer_format' => self::mergeEnumFromArray($offerFormat),
        ];
    }

    /**
     * Merge safety object.
     */
    private static function mergeSafety(array $batchTraits): array
    {
        $toxicityRisks = [];
        $notes = [];

        foreach ($batchTraits as $batch) {
            if (isset($batch['safety']['toxicity_risk'])) {
                $toxicityRisks[] = $batch['safety']['toxicity_risk'];
            }
            if (!empty($batch['safety']['notes'])) {
                $notes[] = $batch['safety']['notes'];
            }
        }

        // For toxicity, take the highest risk level
        $riskOrder = ['low' => 1, 'medium' => 2, 'high' => 3];
        $maxRisk = 'low';
        foreach ($toxicityRisks as $risk) {
            if (($riskOrder[$risk] ?? 0) > ($riskOrder[$maxRisk] ?? 0)) {
                $maxRisk = $risk;
            }
        }

        return [
            'toxicity_risk' => $maxRisk,
            'notes' => !empty($notes) ? implode('; ', array_unique($notes)) : null,
        ];
    }

    /**
     * Merge enum from array of values.
     */
    private static function mergeEnumFromArray(array $values): ?string
    {
        $values = array_filter($values, fn($v) => $v !== null);
        
        if (empty($values)) {
            return null;
        }

        $counts = array_count_values($values);
        arsort($counts);
        
        $topValues = array_keys($counts, max($counts));
        
        if (count($topValues) > 1) {
            return null;
        }
        
        return $topValues[0];
    }

    /**
     * Compute consistency metrics across batches for confidence scoring.
     *
     * @param array $batchTraits
     * @return array ['enum_agreement' => float, 'list_overlap' => float, 'consistency' => float]
     */
    public static function computeConsistencyMetrics(array $batchTraits): array
    {
        if (count($batchTraits) < 2) {
            return ['enum_agreement' => 1.0, 'list_overlap' => 1.0, 'consistency' => 1.0];
        }

        $enumFields = [
            'formality', 'sentence_length', 'paragraph_density', 'pacing', 'emotional_intensity'
        ];

        // Compute enum agreement rate
        $enumAgreements = [];
        foreach ($enumFields as $field) {
            $values = array_column($batchTraits, $field);
            $values = array_filter($values, fn($v) => $v !== null);
            
            if (empty($values)) {
                continue;
            }

            $counts = array_count_values($values);
            $maxCount = max($counts);
            $enumAgreements[] = $maxCount / count($values);
        }

        $enumAgreement = !empty($enumAgreements) ? array_sum($enumAgreements) / count($enumAgreements) : 0.5;

        // Compute list overlap (Jaccard similarity)
        $listFields = ['style_signatures', 'do_not_do', 'must_do', 'tone'];
        $overlaps = [];

        foreach ($listFields as $field) {
            $sets = [];
            foreach ($batchTraits as $batch) {
                $items = $batch[$field] ?? [];
                if (is_array($items)) {
                    $sets[] = array_flip($items);
                }
            }

            if (count($sets) < 2) {
                continue;
            }

            // Compute pairwise Jaccard
            $pairwiseJaccard = [];
            for ($i = 0; $i < count($sets) - 1; $i++) {
                for ($j = $i + 1; $j < count($sets); $j++) {
                    $intersection = count(array_intersect_key($sets[$i], $sets[$j]));
                    $union = count($sets[$i]) + count($sets[$j]) - $intersection;
                    
                    if ($union > 0) {
                        $pairwiseJaccard[] = $intersection / $union;
                    }
                }
            }

            if (!empty($pairwiseJaccard)) {
                $overlaps[] = array_sum($pairwiseJaccard) / count($pairwiseJaccard);
            }
        }

        $listOverlap = !empty($overlaps) ? array_sum($overlaps) / count($overlaps) : 0.5;

        $consistency = ($enumAgreement + $listOverlap) / 2;

        return [
            'enum_agreement' => round($enumAgreement, 3),
            'list_overlap' => round($listOverlap, 3),
            'consistency' => round($consistency, 3),
        ];
    }
}
