<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('swipe_structures', function (Blueprint $table) {
            if (!Schema::hasColumn('swipe_structures', 'organization_id')) {
                $table->uuid('organization_id')->nullable()->after('id');
            }
        });

        // Backfill organization_id from swipe_items.organization_id where possible.
        try {
            DB::statement(
                "UPDATE swipe_structures ss\n" .
                "SET organization_id = si.organization_id\n" .
                "FROM swipe_items si\n" .
                "WHERE ss.swipe_item_id = si.id\n" .
                "  AND ss.organization_id IS NULL"
            );
        } catch (\Throwable) {
            // best-effort
        }

        // Allow library/manual structures (no source swipe item)
        // Postgres: drop NOT NULL constraint.
        try {
            DB::statement('ALTER TABLE swipe_structures ALTER COLUMN swipe_item_id DROP NOT NULL');
        } catch (\Throwable) {
            // best-effort
        }

        Schema::table('swipe_structures', function (Blueprint $table) {
            try { $table->index(['organization_id', 'is_ephemeral'], 'swipe_structures_org_ephemeral_idx'); } catch (\Throwable) {}
            try { $table->index(['organization_id', 'deleted_at'], 'swipe_structures_org_deleted_idx'); } catch (\Throwable) {}
            try { $table->index(['organization_id', 'confidence'], 'swipe_structures_org_confidence_idx'); } catch (\Throwable) {}
        });
    }

    public function down(): void
    {
        Schema::table('swipe_structures', function (Blueprint $table) {
            try { $table->dropIndex('swipe_structures_org_ephemeral_idx'); } catch (\Throwable) {}
            try { $table->dropIndex('swipe_structures_org_deleted_idx'); } catch (\Throwable) {}
            try { $table->dropIndex('swipe_structures_org_confidence_idx'); } catch (\Throwable) {}

            if (Schema::hasColumn('swipe_structures', 'organization_id')) {
                $table->dropColumn('organization_id');
            }
        });

        // We intentionally do not re-add NOT NULL to swipe_item_id on down.
    }
};
