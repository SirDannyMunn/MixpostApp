<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('knowledge_chunks')) {
            Schema::table('knowledge_chunks', function (Blueprint $table) {
                if (!Schema::hasColumn('knowledge_chunks', 'chunk_role')) {
                    $table->string('chunk_role', 50)->nullable()->after('chunk_type');
                }
                if (!Schema::hasColumn('knowledge_chunks', 'authority')) {
                    $table->string('authority', 20)->nullable()->after('chunk_role');
                }
                if (!Schema::hasColumn('knowledge_chunks', 'confidence')) {
                    $table->float('confidence')->nullable()->after('authority');
                }
                if (!Schema::hasColumn('knowledge_chunks', 'time_horizon')) {
                    $table->string('time_horizon', 20)->nullable()->after('confidence');
                }
                if (!Schema::hasColumn('knowledge_chunks', 'source_type')) {
                    $table->string('source_type', 50)->nullable()->after('time_horizon');
                }
                if (!Schema::hasColumn('knowledge_chunks', 'source_ref')) {
                    $table->json('source_ref')->nullable()->after('source_type');
                }

                $table->index(['organization_id', 'user_id', 'source_type', 'chunk_role'], 'kc_org_user_source_role_idx');
                $table->index(['knowledge_item_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('knowledge_chunks')) {
            Schema::table('knowledge_chunks', function (Blueprint $table) {
                if (Schema::hasColumn('knowledge_chunks', 'source_ref')) {
                    $table->dropColumn('source_ref');
                }
                if (Schema::hasColumn('knowledge_chunks', 'source_type')) {
                    $table->dropColumn('source_type');
                }
                if (Schema::hasColumn('knowledge_chunks', 'time_horizon')) {
                    $table->dropColumn('time_horizon');
                }
                if (Schema::hasColumn('knowledge_chunks', 'confidence')) {
                    $table->dropColumn('confidence');
                }
                if (Schema::hasColumn('knowledge_chunks', 'authority')) {
                    $table->dropColumn('authority');
                }
                if (Schema::hasColumn('knowledge_chunks', 'chunk_role')) {
                    $table->dropColumn('chunk_role');
                }
            });
        }
    }
};

