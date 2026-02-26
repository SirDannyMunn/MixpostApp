<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('generation_quality_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');
            $table->uuid('generated_post_id')->nullable();
            $table->uuid('snapshot_id')->nullable();
            $table->string('intent', 50)->nullable();
            $table->float('overall_score')->default(0);
            $table->json('scores')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organization_id','intent']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_quality_reports');
    }
};

