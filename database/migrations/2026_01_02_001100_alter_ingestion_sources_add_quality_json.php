<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('ingestion_sources') && !Schema::hasColumn('ingestion_sources', 'quality')) {
            Schema::table('ingestion_sources', function (Blueprint $table) {
                $table->json('quality')->nullable()->after('quality_score');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ingestion_sources') && Schema::hasColumn('ingestion_sources', 'quality')) {
            Schema::table('ingestion_sources', function (Blueprint $table) {
                $table->dropColumn('quality');
            });
        }
    }
};

