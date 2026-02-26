<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('knowledge_item_id')->constrained('knowledge_items')->onDelete('cascade');
            $table->uuid('organization_id');
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->longText('chunk_text');
            $table->string('chunk_type', 50)->default('misc');
            $table->jsonb('tags')->nullable();
            $table->integer('token_count')->default(0);
            $table->jsonb('embedding')->nullable();
            $table->string('embedding_model', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organization_id','chunk_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
