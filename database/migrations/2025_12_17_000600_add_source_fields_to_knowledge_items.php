<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('knowledge_items')) {
            Schema::table('knowledge_items', function (Blueprint $table) {
                if (!Schema::hasColumn('knowledge_items', 'source_id')) {
                    $table->string('source_id', 191)->nullable()->after('source');
                }
                if (!Schema::hasColumn('knowledge_items', 'source_platform')) {
                    $table->string('source_platform', 100)->nullable()->after('source_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('knowledge_items')) {
            Schema::table('knowledge_items', function (Blueprint $table) {
                if (Schema::hasColumn('knowledge_items', 'source_platform')) {
                    $table->dropColumn('source_platform');
                }
                if (Schema::hasColumn('knowledge_items', 'source_id')) {
                    $table->dropColumn('source_id');
                }
            });
        }
    }
};

