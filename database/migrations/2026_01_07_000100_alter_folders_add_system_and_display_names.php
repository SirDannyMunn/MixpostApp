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

        // Prefer renaming legacy `name` column to `system_name` (doesn't require doctrine/dbal).
        if (Schema::hasColumn('folders', 'name') && !Schema::hasColumn('folders', 'system_name')) {
            try {
                DB::statement('ALTER TABLE folders RENAME COLUMN name TO system_name');
            } catch (\Throwable) {
                // ignore (already renamed or unsupported)
            }
        }

        // If neither column exists (unexpected), add `system_name`.
        Schema::table('folders', function (Blueprint $table) {
            if (!Schema::hasColumn('folders', 'system_name')) {
                $table->string('system_name')->nullable();
            }
            if (!Schema::hasColumn('folders', 'display_name')) {
                $table->string('display_name', 120)->nullable();
            }
            if (!Schema::hasColumn('folders', 'system_named_at')) {
                $table->timestamp('system_named_at')->nullable();
            }
            if (!Schema::hasColumn('folders', 'display_renamed_at')) {
                $table->timestamp('display_renamed_at')->nullable();
            }
        });

        // Backfill system_name for any existing rows.
        try {
            // Postgres: id is UUID; use a short prefix for a stable default label.
            DB::statement("UPDATE folders SET system_name = COALESCE(NULLIF(system_name, ''), CONCAT('Folder ', LEFT(id::text, 8))) WHERE system_name IS NULL OR system_name = ''");
        } catch (\Throwable) {
            // Fallback for other drivers
            DB::table('folders')
                ->whereNull('system_name')
                ->orWhere('system_name', '')
                ->orderBy('id')
                ->chunkById(200, function ($rows) {
                    foreach ($rows as $row) {
                        $id = (string) ($row->id ?? '');
                        $short = $id !== '' ? substr($id, 0, 8) : '';
                        $label = $short !== '' ? ('Folder ' . $short) : 'Folder';
                        DB::table('folders')->where('id', $row->id)->update(['system_name' => $label]);
                    }
                }, 'id');
        }

        // Backfill auditing timestamps best-effort.
        try {
            DB::statement('UPDATE folders SET system_named_at = COALESCE(system_named_at, created_at, now()) WHERE system_named_at IS NULL');
        } catch (\Throwable) {
            // ignore
        }

        // Enforce NOT NULL where supported.
        try {
            DB::statement('ALTER TABLE folders ALTER COLUMN system_name SET NOT NULL');
        } catch (\Throwable) {
            // ignore
        }
    }

    public function down(): void
    {
        // Non-destructive: keep data. Intentionally does not rename system_name back to name.
    }
};
