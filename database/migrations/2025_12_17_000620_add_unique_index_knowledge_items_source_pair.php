<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('knowledge_items')) {
            // Partial unique index to prevent duplicate ingests for the same source_id + raw_text within an org
            // Postgres syntax; harmless if index already exists
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS knowledge_items_unique_source_pair ON knowledge_items (organization_id, source, source_id, raw_text_sha256) WHERE source_id IS NOT NULL;");
        }
    }

    public function down(): void
    {
        // Drop the unique index if present
        DB::statement("DROP INDEX IF EXISTS knowledge_items_unique_source_pair;");
    }
};

