<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_canvas_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('title')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);

            $table->longText('current_document_content')->nullable();
            $table->uuid('current_version_id')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'user_id', 'updated_at']);
            $table->index('current_version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_canvas_conversations');
    }
};

