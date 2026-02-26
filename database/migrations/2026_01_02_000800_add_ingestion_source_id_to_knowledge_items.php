<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('knowledge_items') && !Schema::hasColumn('knowledge_items', 'ingestion_source_id')) {
            Schema::table('knowledge_items', function (Blueprint $table) {
                $table->foreignUuid('ingestion_source_id')->nullable()->after('user_id');
                $table->index('ingestion_source_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('knowledge_items') && Schema::hasColumn('knowledge_items', 'ingestion_source_id')) {
            Schema::table('knowledge_items', function (Blueprint $table) {
                $table->dropIndex(['ingestion_source_id']);
                $table->dropColumn('ingestion_source_id');
            });
        }
    }
};

