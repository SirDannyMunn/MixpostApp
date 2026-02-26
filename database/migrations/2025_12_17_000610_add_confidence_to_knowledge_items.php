<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('knowledge_items') && !Schema::hasColumn('knowledge_items', 'confidence')) {
            Schema::table('knowledge_items', function (Blueprint $table) {
                $table->float('confidence')->nullable()->after('metadata');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('knowledge_items') && Schema::hasColumn('knowledge_items', 'confidence')) {
            Schema::table('knowledge_items', function (Blueprint $table) {
                $table->dropColumn('confidence');
            });
        }
    }
};

