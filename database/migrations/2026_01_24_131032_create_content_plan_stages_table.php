<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_plan_stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('content_plan_id')->constrained('content_plans')->onDelete('cascade');
            $table->integer('day_index');
            $table->string('stage_type');
            $table->text('intent')->nullable();
            $table->text('prompt_seed')->nullable();
            $table->timestamps();
            
            $table->index(['content_plan_id', 'day_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_plan_stages');
    }
};
