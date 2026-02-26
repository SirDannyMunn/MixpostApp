<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_entitlements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->index();

            if (config('billing.tenancy.enabled')) {
                $table->string(config('billing.tenancy.tenant_key', 'tenant_id'))->nullable()->index();
            } else {
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
            }

            $table->string('plan_code')->index();
            $table->enum('source', ['stripe', 'coupon', 'admin', 'migration'])->default('coupon');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->unsignedBigInteger('coupon_code_id')->nullable();
            $table->timestamps();

            $table->foreign('coupon_code_id')->references('id')->on('coupon_codes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_entitlements');
    }
};

