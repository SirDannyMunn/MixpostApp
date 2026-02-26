<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('credit_ledgers', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->integer('delta')->comment('Change amount: positive for additions, negative for consumption');
            $table->integer('balance_after')->comment('Balance after this transaction');
            $table->string('reason')->nullable()->comment('Human-readable reason for the transaction');
            $table->json('meta')->nullable()->comment('Additional metadata');
            $table->string('idempotency_key')->nullable()->unique()->comment('For preventing duplicate operations');

            // Multi-tenancy support
            if (config('billing.tenancy.enabled')) {
                $table->nullableMorphs('tenant');
            }

            $table->timestamps();

            // Indexes for performance
            $table->index(['billable_type', 'billable_id', 'created_at']);
            $table->index('balance_after');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_ledgers');
    }
};
