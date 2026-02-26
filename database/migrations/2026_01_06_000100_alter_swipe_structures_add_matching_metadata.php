<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('swipe_structures', function (Blueprint $table) {
            if (!Schema::hasColumn('swipe_structures', 'title')) {
                $table->string('title')->nullable()->after('id');
            }
            if (!Schema::hasColumn('swipe_structures', 'is_ephemeral')) {
                $table->boolean('is_ephemeral')->default(false)->after('language_signals');
            }
            if (!Schema::hasColumn('swipe_structures', 'origin')) {
                $table->string('origin', 50)->nullable()->after('is_ephemeral');
            }
            if (!Schema::hasColumn('swipe_structures', 'created_by_user_id')) {
                $table->uuid('created_by_user_id')->nullable()->after('origin');
            }
            if (!Schema::hasColumn('swipe_structures', 'last_used_at')) {
                $table->timestamp('last_used_at')->nullable()->after('created_at');
            }
            if (!Schema::hasColumn('swipe_structures', 'use_count')) {
                $table->integer('use_count')->default(0)->after('last_used_at');
            }
            if (!Schema::hasColumn('swipe_structures', 'success_count')) {
                $table->integer('success_count')->default(0)->after('use_count');
            }
            if (!Schema::hasColumn('swipe_structures', 'failure_count')) {
                $table->integer('failure_count')->default(0)->after('success_count');
            }
            if (!Schema::hasColumn('swipe_structures', 'deleted_at')) {
                $table->timestamp('deleted_at')->nullable()->after('failure_count');
            }

            // Indexes for ranking + filtering
            if (!Schema::hasColumn('swipe_structures', 'is_ephemeral') || !Schema::hasColumn('swipe_structures', 'last_used_at')) {
                // no-op; defensive, indexes added below anyway
            }
        });

        Schema::table('swipe_structures', function (Blueprint $table) {
            // Add indexes in a separate Schema::table call to keep things simple.
            // Note: Laravel doesn't support DESC index modifiers portably; we rely on query ORDER BY.
            $table->index(['swipe_item_id'], 'swipe_structures_swipe_item_idx');
            $table->index(['is_ephemeral'], 'swipe_structures_is_ephemeral_idx');
            $table->index(['last_used_at'], 'swipe_structures_last_used_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('swipe_structures', function (Blueprint $table) {
            // Drop indexes if they exist
            try { $table->dropIndex('swipe_structures_swipe_item_idx'); } catch (\Throwable) {}
            try { $table->dropIndex('swipe_structures_is_ephemeral_idx'); } catch (\Throwable) {}
            try { $table->dropIndex('swipe_structures_last_used_at_idx'); } catch (\Throwable) {}

            foreach (['title','is_ephemeral','origin','created_by_user_id','last_used_at','use_count','success_count','failure_count','deleted_at'] as $col) {
                if (Schema::hasColumn('swipe_structures', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
