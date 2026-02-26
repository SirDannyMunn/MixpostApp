<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_plan_posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('content_plan_stage_id')->constrained('content_plan_stages')->onDelete('cascade');
            $table->uuid('organization_id');
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->text('draft_text')->nullable();
            $table->string('status')->default('pending');
            $table->uuid('generation_snapshot_id')->nullable();
            $table->timestamps();
            
            $table->index(['content_plan_stage_id']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_plan_posts');
    }
};
