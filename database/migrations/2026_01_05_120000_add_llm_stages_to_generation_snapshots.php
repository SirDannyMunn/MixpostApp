<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('generation_snapshots', function (Blueprint $table) {
            $table->json('llm_stages')->nullable()->after('repair_metrics');
        });
    }

    public function down(): void
    {
        Schema::table('generation_snapshots', function (Blueprint $table) {
            $table->dropColumn('llm_stages');
        });
    }
};
