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
        Schema::create('billing_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider_event_id')->unique()->comment('Unique event ID from payment provider');
            $table->string('type')->comment('Event type (e.g., payment_intent.succeeded)');
            $table->json('payload')->comment('Full webhook payload');
            $table->boolean('processed')->default(false)->comment('Whether this webhook has been processed');
            $table->text('error')->nullable()->comment('Error message if processing failed');

            // Multi-tenancy support
            if (config('billing.tenancy.enabled')) {
                $table->nullableMorphs('tenant');
            }

            $table->timestamps();

            // Indexes for performance
            $table->index('type');
            $table->index(['processed', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_webhook_logs');
    }
};
