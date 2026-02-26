<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_codes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code_hash')->unique();
            $table->string('plan_code')->index();
            $table->string('name')->nullable();
            $table->enum('status', ['active', 'disabled'])->default('active');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redemptions_count')->default(0);
            $table->boolean('once_per_user')->default(true);
            $table->unsignedInteger('duration_days')->nullable();
            $table->unsignedInteger('grants_trial_days')->nullable();
            $table->json('metadata')->nullable();

            if (config('billing.tenancy.enabled')) {
                $table->string(config('billing.tenancy.tenant_key', 'tenant_id'))->nullable()->index();
            } else {
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
            }

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_codes');
    }
};

