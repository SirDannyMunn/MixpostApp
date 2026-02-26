<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_canvas_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('ai_canvas_conversations')->cascadeOnDelete();

            $table->enum('role', ['user', 'assistant', 'system']);
            $table->text('content');

            $table->json('classification')->nullable();
            $table->json('command')->nullable();

            $table->uuid('created_version_id')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_canvas_messages');
    }
};

