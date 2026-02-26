<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('llm_calls', function (Blueprint $table) {
            // Provider and pipeline context
            $table->string('provider', 50)->nullable()->after('model');
            $table->string('pipeline_stage', 50)->nullable()->after('provider');
            $table->string('request_type', 50)->nullable()->after('pipeline_stage');

            // Detailed token accounting (renaming existing)
            $table->renameColumn('input_tokens', 'prompt_tokens');
            $table->renameColumn('output_tokens', 'completion_tokens');
            $table->integer('total_tokens')->nullable()->after('completion_tokens');

            // Cost calculation
            $table->decimal('unit_cost_usd', 10, 6)->nullable()->after('cost_usd');
            $table->string('pricing_source', 50)->nullable()->after('unit_cost_usd');

            // Entity linkage
            $table->string('related_entity_type', 50)->nullable()->after('request_hash');
            $table->uuid('related_entity_id')->nullable()->after('related_entity_type');

            // Model details
            $table->string('model_version', 100)->nullable()->after('model');

            // Completeness flag
            $table->boolean('record_complete')->default(false)->after('created_at');
        });

        // Add indexes
        Schema::table('llm_calls', function (Blueprint $table) {
            $table->index(['organization_id', 'pipeline_stage', 'request_type'], 'llm_calls_org_stage_idx');
            $table->index('created_at', 'llm_calls_created_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('llm_calls', function (Blueprint $table) {
            $table->dropIndex('llm_calls_org_stage_idx');
            $table->dropIndex('llm_calls_created_at_idx');
        });

        Schema::table('llm_calls', function (Blueprint $table) {
            $table->dropColumn([
                'provider',
                'pipeline_stage',
                'request_type',
                'total_tokens',
                'unit_cost_usd',
                'pricing_source',
                'related_entity_type',
                'related_entity_id',
                'model_version',
                'record_complete',
            ]);

            $table->renameColumn('prompt_tokens', 'input_tokens');
            $table->renameColumn('completion_tokens', 'output_tokens');
        });
    }
};
