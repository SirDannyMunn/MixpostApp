<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Make token columns nullable to support partial data logging
        DB::statement('ALTER TABLE llm_calls ALTER COLUMN prompt_tokens DROP NOT NULL');
        DB::statement('ALTER TABLE llm_calls ALTER COLUMN completion_tokens DROP NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: Reversing this might fail if there are NULL values
        DB::statement('ALTER TABLE llm_calls ALTER COLUMN prompt_tokens SET NOT NULL');
        DB::statement('ALTER TABLE llm_calls ALTER COLUMN completion_tokens SET NOT NULL');
    }
};
