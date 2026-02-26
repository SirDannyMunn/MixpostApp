<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('generated_posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->string('platform', 50);
            $table->string('intent', 50)->nullable();
            $table->string('funnel_stage', 10)->nullable();
            $table->string('topic', 255)->nullable();
            $table->uuid('template_id')->nullable();
            $table->jsonb('request');
            $table->jsonb('context_snapshot')->nullable();
            $table->longText('content')->nullable();
            $table->string('status', 20)->default('queued');
            $table->jsonb('validation')->nullable();
            $table->timestamps();
            $table->index(['organization_id','created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_posts');
    }
};
