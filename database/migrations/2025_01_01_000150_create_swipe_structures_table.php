<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('swipe_structures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('swipe_item_id')->constrained('swipe_items')->onDelete('cascade');
            $table->string('intent', 50)->nullable();
            $table->string('funnel_stage', 10)->nullable();
            $table->string('hook_type', 100)->nullable();
            $table->string('cta_type', 20)->default('none');
            $table->jsonb('structure');
            $table->jsonb('language_signals')->nullable();
            $table->float('confidence')->default(0);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swipe_structures');
    }
};
