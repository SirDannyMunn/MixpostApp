<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('generation_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');
            $table->uuid('generated_post_id')->nullable();
            $table->string('platform', 50)->nullable();
            $table->text('prompt');
            $table->json('classification')->nullable();
            $table->uuid('template_id')->nullable();
            $table->json('template_data')->nullable();
            $table->json('chunks')->nullable();
            $table->json('facts')->nullable();
            $table->json('swipes')->nullable();
            $table->longText('user_context')->nullable();
            $table->json('options')->nullable();
            $table->longText('output_content')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organization_id','user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_snapshots');
    }
};

