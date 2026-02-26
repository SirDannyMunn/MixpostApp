<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_canvas_conversations', function (Blueprint $table) {
            $table->uuid('last_snapshot_id')->nullable()->after('current_version_id');
            $table->uuid('active_voice_profile_id')->nullable()->after('last_snapshot_id');
            $table->uuid('active_template_id')->nullable()->after('active_voice_profile_id');
            $table->json('active_swipe_ids')->nullable()->after('active_template_id');
            $table->json('active_fact_ids')->nullable()->after('active_swipe_ids');
            $table->json('active_reference_ids')->nullable()->after('active_fact_ids');

            $table->index('last_snapshot_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_canvas_conversations', function (Blueprint $table) {
            $table->dropIndex(['last_snapshot_id']);
            $table->dropColumn([
                'last_snapshot_id',
                'active_voice_profile_id',
                'active_template_id',
                'active_swipe_ids',
                'active_fact_ids',
                'active_reference_ids',
            ]);
        });
    }
};

