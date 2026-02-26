<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_canvas_document_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('ai_canvas_conversations')->cascadeOnDelete();
            $table->uuid('message_id')->nullable();

            $table->unsignedInteger('version_number');
            $table->string('title')->nullable();

            $table->longText('content');
            $table->text('content_preview')->nullable();
            $table->unsignedInteger('word_count')->nullable();

            $table->enum('command_type', ['replace_document', 'replace_section', 'insert_content'])->nullable();
            $table->string('command_target')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['conversation_id', 'version_number']);
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_canvas_document_versions');
    }
};

