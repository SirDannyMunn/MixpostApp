<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingestion_evaluations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');
            $table->string('title')->nullable();
            $table->string('status')->default('running');
            $table->string('format')->default('both');
            $table->json('options')->nullable();
            $table->json('scores')->nullable();
            $table->json('issues')->nullable();
            $table->json('recommendations')->nullable();
            $table->json('report_paths')->nullable();
            $table->uuid('ingestion_source_id')->nullable();
            $table->uuid('knowledge_item_id')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_evaluations');
    }
};

