<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sentence_rewrites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignUuid('generated_post_id')->nullable()->constrained('generated_posts')->nullOnDelete();
            $table->text('original_sentence');
            $table->string('instruction', 500);
            $table->text('rewritten_sentence')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organization_id','user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sentence_rewrites');
    }
};
