<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('processing_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->string('subject_type', 50);
            $table->uuid('subject_id');
            $table->string('processor', 50);
            $table->string('status', 20)->default('queued');
            $table->integer('attempt')->default(0);
            $table->text('error')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();
            $table->index(['organization_id','subject_type','subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_runs');
    }
};
