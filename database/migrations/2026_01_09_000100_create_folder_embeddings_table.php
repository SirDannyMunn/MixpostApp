<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('folder_embeddings')) {
            return;
        }

        Schema::create('folder_embeddings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('folder_id')->unique();
            $table->uuid('org_id');
            $table->unsignedInteger('text_version')->default(1);
            $table->text('representation_text');
            $table->timestamp('stale_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('org_id');
        });

        // pgvector column + index (best-effort)
        try {
            DB::statement('ALTER TABLE folder_embeddings ADD COLUMN embedding vector(1536);');
        } catch (\Throwable) {
            // ignore if unsupported or already added
        }
        try {
            DB::statement('CREATE INDEX IF NOT EXISTS folder_embeddings_embedding_hnsw ON folder_embeddings USING hnsw (embedding vector_cosine_ops);');
        } catch (\Throwable) {
            // ignore if unsupported
        }

        // Foreign keys (best-effort)
        try {
            Schema::table('folder_embeddings', function (Blueprint $table) {
                try { $table->foreign('folder_id')->references('id')->on('folders')->cascadeOnDelete(); } catch (\Throwable) {}
                try { $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete(); } catch (\Throwable) {}
            });
        } catch (\Throwable) {
            // ignore if unsupported
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('folder_embeddings')) {
            return;
        }

        try {
            DB::statement('DROP INDEX IF EXISTS folder_embeddings_embedding_hnsw;');
        } catch (\Throwable) {
            // ignore
        }

        Schema::dropIfExists('folder_embeddings');
    }
};
