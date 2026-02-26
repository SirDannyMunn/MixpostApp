<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('ingestion_sources')) {
            Schema::create('ingestion_sources', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('organization_id');
                $table->foreignUuid('user_id');
                $table->string('source_type', 50); // bookmark, text, file, transcript, draft, post, ai_output
                $table->string('source_id', 191)->nullable(); // UUID or string id from source
                $table->string('origin', 50)->nullable(); // browser, manual, upload, integration, ai
                $table->string('platform', 100)->nullable(); // twitter, linkedin, notion, etc
                $table->text('raw_url')->nullable();
                $table->longText('raw_text')->nullable();
                $table->string('mime_type', 150)->nullable();
                // Newer fields that were added later via alter migration; include here for fresh installs
                $table->string('title', 500)->nullable();
                $table->json('metadata')->nullable();
                $table->float('confidence_score')->nullable();
                $table->float('quality_score')->nullable();
                $table->string('dedup_hash', 64)->index();
                $table->string('status', 20)->default('pending'); // pending, processing, completed, failed
                $table->text('error')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['source_type', 'source_id']);
                $table->index(['organization_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_sources');
    }
};
