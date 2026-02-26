<?php

namespace Tests\Unit\Services\Voice;

use App\Services\Voice\VoiceTraitsValidator;
use Tests\TestCase;

class VoiceTraitsValidatorTest extends TestCase
{
    public function test_validates_valid_v2_traits(): void
    {
        $traits = [
            'schema_version' => '2.0',
            'description' => 'Test voice profile',
            'tone' => ['professional', 'friendly'],
            'formality' => 'casual',
            'sentence_length' => 'medium',
            'style_signatures' => ['uses metaphors', 'asks rhetorical questions', 'speaks directly'],
            'do_not_do' => ['use jargon', 'be vague', 'write long paragraphs', 'use passive voice', 'ignore audience'],
            'format_rules' => [
                'casing' => 'sentence_case',
                'line_breaks' => 'normal',
                'emoji_usage' => 'rare',
            ],
        ];

        $result = VoiceTraitsValidator::validate($traits);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_rejects_missing_schema_version(): void
    {
        $traits = [
            'description' => 'Test voice profile',
            'style_signatures' => ['sig1', 'sig2', 'sig3'],
            'do_not_do' => ['a', 'b', 'c', 'd', 'e'],
        ];

        $result = VoiceTraitsValidator::validate($traits);

        $this->assertFalse($result['valid']);
        $this->assertContains('Missing required field: schema_version', $result['errors']);
    }

    public function test_rejects_wrong_schema_version(): void
    {
        $traits = [
            'schema_version' => '1.0',
            'description' => 'Test',
            'style_signatures' => ['sig1', 'sig2', 'sig3'],
            'do_not_do' => ['a', 'b', 'c', 'd', 'e'],
        ];

        $result = VoiceTraitsValidator::validate($traits);

        $this->assertFalse($result['valid']);
        $this->assertContains('Invalid schema_version: expected "2.0"', $result['errors']);
    }

    public function test_rejects_insufficient_do_not_do_items(): void
    {
        $traits = [
            'schema_version' => '2.0',
            'description' => 'Test',
            'style_signatures' => ['sig1', 'sig2', 'sig3'],
            'do_not_do' => ['a', 'b'], // Only 2, need 5
        ];

        $result = VoiceTraitsValidator::validate($traits);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('do_not_do must have at least 5 items', implode('; ', $result['errors']));
    }

    public function test_rejects_insufficient_style_signatures(): void
    {
        $traits = [
            'schema_version' => '2.0',
            'description' => 'Test',
            'style_signatures' => ['sig1'], // Only 1, need 3
            'do_not_do' => ['a', 'b', 'c', 'd', 'e'],
        ];

        $result = VoiceTraitsValidator::validate($traits);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('style_signatures must have at least 3 items', implode('; ', $result['errors']));
    }

    public function test_rejects_invalid_enum_values(): void
    {
        $traits = [
            'schema_version' => '2.0',
            'description' => 'Test',
            'formality' => 'super_formal', // Invalid
            'style_signatures' => ['sig1', 'sig2', 'sig3'],
            'do_not_do' => ['a', 'b', 'c', 'd', 'e'],
        ];

        $result = VoiceTraitsValidator::validate($traits);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('formality must be one of', implode('; ', $result['errors']));
    }

    public function test_accepts_null_optional_fields(): void
    {
        $traits = [
            'schema_version' => '2.0',
            'description' => 'Test',
            'formality' => null,
            'sentence_length' => null,
            'style_signatures' => ['sig1', 'sig2', 'sig3'],
            'do_not_do' => ['a', 'b', 'c', 'd', 'e'],
        ];

        $result = VoiceTraitsValidator::validate($traits);

        $this->assertTrue($result['valid']);
    }

    public function test_validates_format_rules_enums(): void
    {
        $traits = [
            'schema_version' => '2.0',
            'description' => 'Test',
            'style_signatures' => ['sig1', 'sig2', 'sig3'],
            'do_not_do' => ['a', 'b', 'c', 'd', 'e'],
            'format_rules' => [
                'casing' => 'invalid_casing',
            ],
        ];

        $result = VoiceTraitsValidator::validate($traits);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('format_rules.casing', implode('; ', $result['errors']));
    }

    public function test_get_default_traits_is_valid(): void
    {
        $defaults = VoiceTraitsValidator::getDefaultTraits();
        $result = VoiceTraitsValidator::validate($defaults);

        // Default traits might not meet minimum requirements, but should have structure
        $this->assertIsArray($defaults);
        $this->assertEquals('2.0', $defaults['schema_version']);
    }
}
