<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('knowledge_items') && !Schema::hasColumn('knowledge_items', 'normalized_claims')) {
            Schema::table('knowledge_items', function (Blueprint $table) {
                // Use jsonb when available (Laravel will map to JSON on MySQL)
                $table->json('normalized_claims')->nullable()->after('metadata');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('knowledge_items') && Schema::hasColumn('knowledge_items', 'normalized_claims')) {
            Schema::table('knowledge_items', function (Blueprint $table) {
                $table->dropColumn('normalized_claims');
            });
        }
    }
};

