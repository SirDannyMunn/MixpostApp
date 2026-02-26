<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Enable pgvector extension.
        // Note: `CREATE EXTENSION` typically requires a superuser. We only attempt it if the
        // extension is not already enabled in the current database.
        $vectorEnabled = DB::selectOne("SELECT 1 FROM pg_extension WHERE extname = 'vector'") !== null;

        if (!$vectorEnabled) {
            try {
                DB::statement("CREATE EXTENSION vector;");
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    "pgvector extension is not enabled for this database and the current DB user cannot enable it. "
                    . "Enable it as a superuser (e.g. `CREATE EXTENSION vector;`) or adjust your DB container/user privileges.",
                    previous: $e
                );
            }
        }

        // Add vector column to knowledge_chunks if table exists
        if (Schema::hasTable('knowledge_chunks')) {
            // Standardize on 1536 dims (compatible with pgvector index limits and text-embedding-3-small)
            DB::statement("ALTER TABLE knowledge_chunks DROP COLUMN IF EXISTS embedding_vec;");
            DB::statement("ALTER TABLE knowledge_chunks ADD COLUMN embedding_vec vector(1536);");

            // HNSW index for fast kNN search using cosine distance
            DB::statement("CREATE INDEX IF NOT EXISTS knowledge_chunks_embedding_vec_hnsw ON knowledge_chunks USING hnsw (embedding_vec vector_cosine_ops);");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('knowledge_chunks') && Schema::hasColumn('knowledge_chunks', 'embedding_vec')) {
            DB::statement("DROP INDEX IF EXISTS knowledge_chunks_embedding_vec_hnsw;");
            DB::statement("ALTER TABLE knowledge_chunks DROP COLUMN embedding_vec;");
        }
        // Keep the extension enabled; removing it could break other objects
    }
};
