<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('swipe_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('platform', 50);
            $table->text('source_url')->nullable();
            $table->string('author_handle', 191)->nullable();
            $table->longText('raw_text');
            $table->char('raw_text_sha256', 64)->index();
            $table->jsonb('engagement')->nullable();
            $table->text('saved_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organization_id','platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swipe_items');
    }
};
