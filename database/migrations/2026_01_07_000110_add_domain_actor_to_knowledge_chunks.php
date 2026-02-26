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
            if (!Schema::hasColumn('knowledge_chunks', 'domain')) {
                $table->string('domain', 50)->nullable()->after('time_horizon');
            }
            if (!Schema::hasColumn('knowledge_chunks', 'actor')) {
                $table->string('actor', 150)->nullable()->after('domain');
            }

            // Keep retrieval fast for normalized-only + domain/role filtering
            $table->index(['organization_id', 'user_id', 'source_variant'], 'kc_org_user_variant_idx');
            $table->index(['organization_id', 'user_id', 'domain'], 'kc_org_user_domain_idx');
            $table->index(['organization_id', 'user_id', 'chunk_role'], 'kc_org_user_role_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('knowledge_chunks')) {
            return;
        }

        Schema::table('knowledge_chunks', function (Blueprint $table) {
            // Index drops (safe even if missing in some DBs)
            try { $table->dropIndex('kc_org_user_variant_idx'); } catch (\Throwable) {}
            try { $table->dropIndex('kc_org_user_domain_idx'); } catch (\Throwable) {}
            try { $table->dropIndex('kc_org_user_role_idx'); } catch (\Throwable) {}

            if (Schema::hasColumn('knowledge_chunks', 'actor')) {
                $table->dropColumn('actor');
            }
            if (Schema::hasColumn('knowledge_chunks', 'domain')) {
                $table->dropColumn('domain');
            }
        });
    }
};
