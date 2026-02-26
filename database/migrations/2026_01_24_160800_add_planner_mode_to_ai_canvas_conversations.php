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
            if (!Schema::hasColumn('ai_canvas_conversations', 'planner_mode')) {
                $table->string('planner_mode')->nullable()->after('active_reference_ids');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_canvas_conversations', function (Blueprint $table) {
            if (Schema::hasColumn('ai_canvas_conversations', 'planner_mode')) {
                $table->dropColumn('planner_mode');
            }
        });
    }
};
