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
        Schema::table('ai_canvas_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('ai_canvas_messages', 'metadata')) {
                $table->jsonb('metadata')->nullable()->after('command');
            }
            if (!Schema::hasColumn('ai_canvas_messages', 'report')) {
                $table->jsonb('report')->nullable()->after('metadata');
            }
            if (!Schema::hasColumn('ai_canvas_messages', 'planner')) {
                $table->jsonb('planner')->nullable()->after('report');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_canvas_messages', function (Blueprint $table) {
            $table->dropColumn(['metadata', 'report', 'planner']);
        });
    }
};
