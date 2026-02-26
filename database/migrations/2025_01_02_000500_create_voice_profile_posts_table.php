<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('voice_profile_posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('voice_profile_id');
            // sw_normalized_content uses string UUID primary keys
            $table->string('normalized_content_id');
            $table->string('source_type')->nullable(); // twitter|linkedin|instagram|youtube|generic
            $table->decimal('weight', 5, 2)->nullable();
            $table->boolean('locked')->default(false);
            $table->timestamps();

            $table->unique(['voice_profile_id','normalized_content_id'], 'vp_posts_unique');
            $table->index('voice_profile_id');
            $table->index('normalized_content_id');

            // Keep FK loose to avoid cross-package constraint issues
            $table->foreign('voice_profile_id')
                ->references('id')->on('voice_profiles')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_profile_posts');
    }
};

