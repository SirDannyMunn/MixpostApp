<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('knowledge_chunk_events')) {
            return;
        }

        Schema::create('knowledge_chunk_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');
            $table->uuid('chunk_id');
            $table->string('event_type', 32);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'chunk_id', 'created_at'], 'kce_org_chunk_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunk_events');
    }
};
