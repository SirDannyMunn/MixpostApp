<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('ingestion_sources') && !Schema::hasColumn('ingestion_sources', 'dedup_reason')) {
            Schema::table('ingestion_sources', function (Blueprint $table) {
                $table->string('dedup_reason', 191)->nullable()->after('status');
                $table->index(['status', 'dedup_reason']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ingestion_sources') && Schema::hasColumn('ingestion_sources', 'dedup_reason')) {
            Schema::table('ingestion_sources', function (Blueprint $table) {
                $table->dropIndex(['status', 'dedup_reason']);
                $table->dropColumn('dedup_reason');
            });
        }
    }
};

