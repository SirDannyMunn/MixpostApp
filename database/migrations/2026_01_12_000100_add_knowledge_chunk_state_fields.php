<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('knowledge_chunks')) {
            return;
        }

        Schema::table('knowledge_chunks', function (Blueprint $table) {
            if (!Schema::hasColumn('knowledge_chunks', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('chunk_kind');
            }
            if (!Schema::hasColumn('knowledge_chunks', 'usage_policy')) {
                $table->string('usage_policy', 20)->default('normal')->after('is_active');
            }
            if (!Schema::hasColumn('knowledge_chunks', 'source_title')) {
                $table->string('source_title', 255)->nullable()->after('source_ref');
            }

            $table->index(['organization_id', 'is_active'], 'kc_org_active_idx');
            $table->index(['organization_id', 'chunk_kind'], 'kc_org_kind_idx');
            $table->index(['organization_id', 'usage_policy'], 'kc_org_policy_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('knowledge_chunks')) {
            return;
        }

        Schema::table('knowledge_chunks', function (Blueprint $table) {
            if (Schema::hasColumn('knowledge_chunks', 'source_title')) {
                $table->dropColumn('source_title');
            }
            if (Schema::hasColumn('knowledge_chunks', 'usage_policy')) {
                $table->dropColumn('usage_policy');
            }
            if (Schema::hasColumn('knowledge_chunks', 'is_active')) {
                $table->dropColumn('is_active');
            }

            $table->dropIndex('kc_org_active_idx');
            $table->dropIndex('kc_org_kind_idx');
            $table->dropIndex('kc_org_policy_idx');
        });
    }
};
