<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('knowledge_chunks')) {
            return;
        }

        // Domain is open-vocabulary; 50 chars is too restrictive.
        // Use raw SQL to avoid requiring doctrine/dbal for ->change().
        $driver = DB::getDriverName();

        try {
            if ($driver === 'pgsql') {
                DB::statement("ALTER TABLE knowledge_chunks ALTER COLUMN domain TYPE varchar(255)");
            } elseif ($driver === 'mysql') {
                DB::statement("ALTER TABLE knowledge_chunks MODIFY domain VARCHAR(255) NULL");
            }
        } catch (\Throwable) {
            // Best-effort; environments may vary.
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('knowledge_chunks')) {
            return;
        }

        $driver = DB::getDriverName();

        try {
            if ($driver === 'pgsql') {
                DB::statement("ALTER TABLE knowledge_chunks ALTER COLUMN domain TYPE varchar(50)");
            } elseif ($driver === 'mysql') {
                DB::statement("ALTER TABLE knowledge_chunks MODIFY domain VARCHAR(50) NULL");
            }
        } catch (\Throwable) {
            // Best-effort.
        }
    }
};
