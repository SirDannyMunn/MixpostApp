<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('generation_snapshots', function (Blueprint $table) {
            $table->json('decision_trace')->nullable()->after('creative_intelligence');
            $table->json('prompt_mutations')->nullable()->after('decision_trace');
            $table->json('ci_rejections')->nullable()->after('prompt_mutations');
            $table->text('ci_summary')->nullable()->after('ci_rejections');
        });
    }

    public function down(): void
    {
        Schema::table('generation_snapshots', function (Blueprint $table) {
            $table->dropColumn(['decision_trace','prompt_mutations','ci_rejections','ci_summary']);
        });
    }
};
