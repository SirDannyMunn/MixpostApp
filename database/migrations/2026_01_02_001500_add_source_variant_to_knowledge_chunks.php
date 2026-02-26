<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('knowledge_chunks')) {
            Schema::table('knowledge_chunks', function (Blueprint $table) {
                if (!Schema::hasColumn('knowledge_chunks', 'source_variant')) {
                    $table->string('source_variant', 20)->nullable()->after('source_type');
                    $table->index(['knowledge_item_id', 'source_variant'], 'kc_item_variant_idx');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('knowledge_chunks') && Schema::hasColumn('knowledge_chunks', 'source_variant')) {
            Schema::table('knowledge_chunks', function (Blueprint $table) {
                $table->dropIndex('kc_item_variant_idx');
                $table->dropColumn('source_variant');
            });
        }
    }
};

