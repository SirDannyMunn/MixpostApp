<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_facts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->string('type', 50);
            $table->text('text');
            $table->float('confidence')->default(0);
            $table->foreignUuid('source_knowledge_item_id')->nullable()->constrained('knowledge_items')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organization_id','type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_facts');
    }
};
