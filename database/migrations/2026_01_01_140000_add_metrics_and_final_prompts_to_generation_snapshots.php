<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('generation_snapshots', function (Blueprint $table) {
            $table->longText('final_system_prompt')->nullable()->after('user_context');
            $table->longText('final_user_prompt')->nullable()->after('final_system_prompt');
            $table->json('token_metrics')->nullable()->after('options');
            $table->json('performance_metrics')->nullable()->after('token_metrics');
            $table->json('repair_metrics')->nullable()->after('performance_metrics');
        });
    }

    public function down(): void
    {
        Schema::table('generation_snapshots', function (Blueprint $table) {
            $table->dropColumn(['final_system_prompt','final_user_prompt','token_metrics','performance_metrics','repair_metrics']);
        });
    }
};

