<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->uuid('conversation_id')->nullable();
            $table->string('plan_type');
            $table->integer('duration_days');
            $table->string('platform');
            $table->text('goal')->nullable();
            $table->text('audience')->nullable();
            $table->uuid('voice_profile_id')->nullable();
            $table->string('status')->default('draft');
            $table->jsonb('continuity_state')->nullable();
            $table->timestamps();
            
            $table->index(['organization_id', 'status']);
            $table->index(['conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_plans');
    }
};
