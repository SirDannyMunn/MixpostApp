<?php

namespace Tests\Unit;

use Tests\TestCase;

class VoiceProfileBooleanValidationTest extends TestCase
{
    public function test_string_false_is_accepted(): void
    {
        $validator = validator(['include_metadata' => 'false'], [
            'include_metadata' => ['nullable', 'in:true,false,1,0'],
        ]);

        $this->assertFalse($validator->fails());
        $this->assertEquals('false', $validator->validated()['include_metadata']);
    }

    public function test_string_true_is_accepted(): void
    {
        $validator = validator(['include_metadata' => 'true'], [
            'include_metadata' => ['nullable', 'in:true,false,1,0'],
        ]);

        $this->assertFalse($validator->fails());
        $this->assertEquals('true', $validator->validated()['include_metadata']);
    }

    public function test_numeric_zero_is_accepted(): void
    {
        $validator = validator(['include_metadata' => '0'], [
            'include_metadata' => ['nullable', 'in:true,false,1,0'],
        ]);

        $this->assertFalse($validator->fails());
        $this->assertEquals('0', $validator->validated()['include_metadata']);
    }

    public function test_numeric_one_is_accepted(): void
    {
        $validator = validator(['include_metadata' => '1'], [
            'include_metadata' => ['nullable', 'in:true,false,1,0'],
        ]);

        $this->assertFalse($validator->fails());
        $this->assertEquals('1', $validator->validated()['include_metadata']);
    }

    public function test_invalid_value_is_rejected(): void
    {
        $validator = validator(['include_metadata' => 'invalid'], [
            'include_metadata' => ['nullable', 'in:true,false,1,0'],
        ]);

        $this->assertTrue($validator->fails());
    }

    public function test_filter_var_converts_strings_correctly(): void
    {
        $this->assertTrue(filter_var('true', FILTER_VALIDATE_BOOLEAN));
        $this->assertTrue(filter_var('1', FILTER_VALIDATE_BOOLEAN));
        $this->assertFalse(filter_var('false', FILTER_VALIDATE_BOOLEAN));
        $this->assertFalse(filter_var('0', FILTER_VALIDATE_BOOLEAN));
        $this->assertFalse(filter_var(false, FILTER_VALIDATE_BOOLEAN));
    }
}
