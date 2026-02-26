<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('coupon_code_id');
            $table->unsignedBigInteger('user_id')->index();

            if (config('billing.tenancy.enabled')) {
                $table->string(config('billing.tenancy.tenant_key', 'tenant_id'))->nullable()->index();
            } else {
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
            }

            $table->timestamp('redeemed_at');
            $table->string('request_ip')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('idempotency_key')->nullable()->index();
            $table->json('metadata')->nullable();

            $table->foreign('coupon_code_id')->references('id')->on('coupon_codes')->onDelete('cascade');
            $table->unique(['coupon_code_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
    }
};
