<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('knowledge_llm_outputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('knowledge_item_id')->constrained('knowledge_items')->onDelete('cascade');
            $table->string('model', 255)->nullable();
            $table->string('prompt_hash', 64)->index();
            $table->json('raw_output');
            $table->json('parsed_output')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['knowledge_item_id', 'created_at'], 'klo_item_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_llm_outputs');
    }
};
