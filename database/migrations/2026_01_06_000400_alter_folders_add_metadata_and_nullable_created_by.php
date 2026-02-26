<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('folders')) {
            return;
        }

        // Add metadata for AI-inferred folder provenance/context
        Schema::table('folders', function (Blueprint $table) {
            if (!Schema::hasColumn('folders', 'metadata')) {
                // Postgres-first (this app uses pgvector); safe to no-op elsewhere if unsupported.
                try {
                    $table->jsonb('metadata')->nullable();
                } catch (\Throwable) {
                    $table->json('metadata')->nullable();
                }
            }
        });

        // Make created_by nullable to allow AI/system-created folders
        try {
            DB::statement('ALTER TABLE folders ALTER COLUMN created_by DROP NOT NULL');
        } catch (\Throwable) {
            // ignore (already nullable or unsupported)
        }

        // Ensure FK allows nulls on user deletion (best-effort)
        try {
            Schema::table('folders', function (Blueprint $table) {
                try { $table->dropForeign(['created_by']); } catch (\Throwable) {}
            });
        } catch (\Throwable) {
            // ignore
        }

        try {
            Schema::table('folders', function (Blueprint $table) {
                // Recreate FK with nullOnDelete when possible
                try {
                    $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
                } catch (\Throwable) {
                    // ignore
                }
            });
        } catch (\Throwable) {
            // ignore
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive; do not drop metadata or re-tighten constraints automatically.
    }
};
