<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('llm_calls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('purpose', 50);
            $table->string('model', 100);
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 4)->nullable();
            $table->integer('latency_ms')->default(0);
            $table->char('request_hash', 64)->nullable();
            $table->string('status', 20)->default('ok');
            $table->string('error_code', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['purpose','created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_calls');
    }
};
