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
        Schema::table('ai_canvas_conversations', function (Blueprint $table) {
            $table->string('planner_mode')->nullable()->after('active_reference_ids');
            $table->jsonb('planner_state')->nullable()->after('planner_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_canvas_conversations', function (Blueprint $table) {
            $table->dropColumn(['planner_mode', 'planner_state']);
        });
    }
};
