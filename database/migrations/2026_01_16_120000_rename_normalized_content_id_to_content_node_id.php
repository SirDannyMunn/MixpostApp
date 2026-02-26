<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // First rename the column
        Schema::table('voice_profile_posts', function (Blueprint $table) {
            $table->renameColumn('normalized_content_id', 'content_node_id');
        });

        // Update indexes after rename - handle different index naming conventions
        Schema::table('voice_profile_posts', function (Blueprint $table) {
            // Drop old unique constraint
            $table->dropUnique('vp_posts_unique');
        });

        // Try to drop the old index (may have different names depending on when it was created)
        try {
            DB::statement('DROP INDEX IF EXISTS voice_profile_posts_normalized_content_id_index');
        } catch (\Exception $e) {
            // Ignore if it doesn't exist
        }

        Schema::table('voice_profile_posts', function (Blueprint $table) {
            // Create new unique constraint and index with correct names
            $table->unique(['voice_profile_id', 'content_node_id'], 'vp_posts_unique');
            $table->index('content_node_id', 'vp_posts_content_node_idx');
        });
    }

    public function down(): void
    {
        Schema::table('voice_profile_posts', function (Blueprint $table) {
            // Drop new indexes
            $table->dropUnique('vp_posts_unique');
            $table->dropIndex('vp_posts_content_node_idx');
        });

        Schema::table('voice_profile_posts', function (Blueprint $table) {
            // Rename back
            $table->renameColumn('content_node_id', 'normalized_content_id');
        });

        Schema::table('voice_profile_posts', function (Blueprint $table) {
            // Restore old indexes
            $table->unique(['voice_profile_id', 'normalized_content_id'], 'vp_posts_unique');
            $table->index('normalized_content_id');
        });
    }
};
