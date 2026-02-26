<?php

namespace App\Services\Voice;

class VoiceTraitsValidator
{
    private const VALID_FORMALITY = ['none', 'casual', 'neutral', 'formal', null];
    private const VALID_SENTENCE_LENGTH = ['short', 'medium', 'long', null];
    private const VALID_PARAGRAPH_DENSITY = ['tight', 'normal', 'airy', null];
    private const VALID_PACING = ['slow', 'medium', 'fast', null];
    private const VALID_EMOTIONAL_INTENSITY = ['low', 'medium', 'high', null];
    
    private const VALID_CASING = ['lowercase', 'sentence_case', 'title_case', 'mixed', null];
    private const VALID_LINE_BREAKS = ['heavy', 'normal', 'minimal', null];
    private const VALID_BULLETS = ['none', 'light', 'heavy', null];
    private const VALID_NUMBERED_LISTS = ['never', 'sometimes', 'often', null];
    private const VALID_EMOJI_USAGE = ['none', 'rare', 'normal', 'heavy', null];
    private const VALID_PROFANITY = ['none', 'light', 'moderate', 'heavy', null];
    private const VALID_URL_STYLE = ['raw', 'markdown', 'no_urls', null];
    
    private const VALID_CTA_PRESENCE = ['none', 'soft', 'hard', null];
    private const VALID_OFFER_FORMAT = ['none', 'simple', 'numbered_offers', 'multi_offer_pitch', null];
    
    private const VALID_TOXICITY_RISK = ['low', 'medium', 'high'];

    /**
     * Validate v2 traits schema.
     *
     * @param array $traits
     * @return array ['valid' => bool, 'errors' => string[]]
     */
    public static function validate(array $traits): array
    {
        $errors = [];

        // Required top-level keys
        if (empty($traits['schema_version'])) {
            $errors[] = 'Missing required field: schema_version';
        } elseif ($traits['schema_version'] !== '2.0') {
            $errors[] = 'Invalid schema_version: expected "2.0"';
        }

        if (!isset($traits['description']) || !is_string($traits['description'])) {
            $errors[] = 'Missing or invalid field: description (must be string)';
        }

        // Validate list fields
        self::validateArrayField($traits, 'tone', $errors, false);
        self::validateArrayField($traits, 'style_signatures', $errors, true, 3);
        self::validateArrayField($traits, 'do_not_do', $errors, true, 5);
        self::validateArrayField($traits, 'must_do', $errors, false);
        self::validateArrayField($traits, 'keyword_bias', $errors, false);
        self::validateArrayField($traits, 'rhetorical_devices', $errors, false);
        self::validateArrayField($traits, 'reference_examples', $errors, false);

        // Validate enum fields
        self::validateEnum($traits, 'formality', self::VALID_FORMALITY, $errors);
        self::validateEnum($traits, 'sentence_length', self::VALID_SENTENCE_LENGTH, $errors);
        self::validateEnum($traits, 'paragraph_density', self::VALID_PARAGRAPH_DENSITY, $errors);
        self::validateEnum($traits, 'pacing', self::VALID_PACING, $errors);
        self::validateEnum($traits, 'emotional_intensity', self::VALID_EMOTIONAL_INTENSITY, $errors);

        // Validate format_rules
        if (isset($traits['format_rules'])) {
            if (!is_array($traits['format_rules'])) {
                $errors[] = 'format_rules must be an object/array';
            } else {
                $fr = $traits['format_rules'];
                self::validateEnum($fr, 'casing', self::VALID_CASING, $errors, 'format_rules.casing');
                self::validateEnum($fr, 'line_breaks', self::VALID_LINE_BREAKS, $errors, 'format_rules.line_breaks');
                self::validateEnum($fr, 'bullets', self::VALID_BULLETS, $errors, 'format_rules.bullets');
                self::validateEnum($fr, 'numbered_lists', self::VALID_NUMBERED_LISTS, $errors, 'format_rules.numbered_lists');
                self::validateEnum($fr, 'emoji_usage', self::VALID_EMOJI_USAGE, $errors, 'format_rules.emoji_usage');
                self::validateEnum($fr, 'profanity', self::VALID_PROFANITY, $errors, 'format_rules.profanity');
                self::validateEnum($fr, 'url_style', self::VALID_URL_STYLE, $errors, 'format_rules.url_style');
            }
        }

        // Validate persona_contract
        if (isset($traits['persona_contract'])) {
            if (!is_array($traits['persona_contract'])) {
                $errors[] = 'persona_contract must be an object/array';
            } else {
                $pc = $traits['persona_contract'];
                self::validateArrayField($pc, 'in_group', $errors, false, 0, 'persona_contract.in_group');
                self::validateArrayField($pc, 'out_group', $errors, false, 0, 'persona_contract.out_group');
                self::validateArrayField($pc, 'status_claims', $errors, false, 0, 'persona_contract.status_claims');
                self::validateArrayField($pc, 'exclusion_language', $errors, false, 0, 'persona_contract.exclusion_language');
                self::validateArrayField($pc, 'credibility_moves', $errors, false, 0, 'persona_contract.credibility_moves');
            }
        }

        // Validate phrases
        if (isset($traits['phrases'])) {
            if (!is_array($traits['phrases'])) {
                $errors[] = 'phrases must be an object/array';
            } else {
                $ph = $traits['phrases'];
                self::validateArrayField($ph, 'openers', $errors, false, 0, 'phrases.openers');
                self::validateArrayField($ph, 'closers', $errors, false, 0, 'phrases.closers');
                self::validateArrayField($ph, 'cta_phrases', $errors, false, 0, 'phrases.cta_phrases');
                self::validateArrayField($ph, 'rejection_phrases', $errors, false, 0, 'phrases.rejection_phrases');
            }
        }

        // Validate structure_patterns
        if (isset($traits['structure_patterns'])) {
            if (!is_array($traits['structure_patterns'])) {
                $errors[] = 'structure_patterns must be an object/array';
            } else {
                $sp = $traits['structure_patterns'];
                self::validateArrayField($sp, 'common_sections', $errors, false, 0, 'structure_patterns.common_sections');
                self::validateEnum($sp, 'cta_presence', self::VALID_CTA_PRESENCE, $errors, 'structure_patterns.cta_presence');
                self::validateEnum($sp, 'offer_format', self::VALID_OFFER_FORMAT, $errors, 'structure_patterns.offer_format');
            }
        }

        // Validate safety
        if (isset($traits['safety'])) {
            if (!is_array($traits['safety'])) {
                $errors[] = 'safety must be an object/array';
            } else {
                $safety = $traits['safety'];
                self::validateEnum($safety, 'toxicity_risk', self::VALID_TOXICITY_RISK, $errors, 'safety.toxicity_risk');
                if (isset($safety['notes']) && !is_string($safety['notes']) && $safety['notes'] !== null) {
                    $errors[] = 'safety.notes must be string or null';
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate that a field is an array with optional minimum length requirement.
     */
    private static function validateArrayField(
        array $data,
        string $key,
        array &$errors,
        bool $required = false,
        int $minLength = 0,
        ?string $path = null
    ): void {
        $fieldPath = $path ?? $key;

        if (!isset($data[$key])) {
            if ($required) {
                $errors[] = "Missing required field: {$fieldPath}";
            }
            return;
        }

        if (!is_array($data[$key])) {
            $errors[] = "{$fieldPath} must be an array";
            return;
        }

        if ($minLength > 0 && count($data[$key]) < $minLength) {
            $errors[] = "{$fieldPath} must have at least {$minLength} items";
        }
    }

    /**
     * Validate that a field is one of the allowed enum values.
     */
    private static function validateEnum(
        array $data,
        string $key,
        array $validValues,
        array &$errors,
        ?string $path = null
    ): void {
        $fieldPath = $path ?? $key;

        if (!isset($data[$key])) {
            return; // Optional field
        }

        if (!in_array($data[$key], $validValues, true)) {
            $validStr = implode('|', array_filter($validValues, fn($v) => $v !== null));
            $errors[] = "{$fieldPath} must be one of: {$validStr} or null";
        }
    }

    /**
     * Get default v2 traits structure (fallback).
     */
    public static function getDefaultTraits(): array
    {
        return [
            'schema_version' => '2.0',
            'description' => 'Default voice profile',
            'tone' => [],
            'persona' => null,
            'formality' => null,
            'sentence_length' => null,
            'paragraph_density' => null,
            'pacing' => null,
            'emotional_intensity' => null,
            'format_rules' => [
                'casing' => null,
                'line_breaks' => null,
                'bullets' => null,
                'numbered_lists' => null,
                'emoji_usage' => null,
                'profanity' => null,
                'url_style' => null,
            ],
            'persona_contract' => [
                'in_group' => [],
                'out_group' => [],
                'status_claims' => [],
                'exclusion_language' => [],
                'credibility_moves' => [],
            ],
            'rhetorical_devices' => [],
            'style_signatures' => [],
            'do_not_do' => [],
            'must_do' => [],
            'keyword_bias' => [],
            'phrases' => [
                'openers' => [],
                'closers' => [],
                'cta_phrases' => [],
                'rejection_phrases' => [],
            ],
            'structure_patterns' => [
                'common_sections' => [],
                'cta_presence' => null,
                'offer_format' => null,
            ],
            'reference_examples' => [],
            'safety' => [
                'toxicity_risk' => 'low',
                'notes' => null,
            ],
        ];
    }
}
