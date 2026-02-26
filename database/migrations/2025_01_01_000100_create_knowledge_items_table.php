<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('knowledge_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->string('type', 50);
            $table->string('source', 50);
            $table->string('title', 500)->nullable();
            $table->longText('raw_text');
            $table->char('raw_text_sha256', 64)->index();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('ingested_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id','created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_items');
    }
};
