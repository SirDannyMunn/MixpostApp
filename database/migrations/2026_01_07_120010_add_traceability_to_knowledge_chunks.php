<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('knowledge_chunks')) {
            return;
        }

        Schema::table('knowledge_chunks', function (Blueprint $table) {
            if (!Schema::hasColumn('knowledge_chunks', 'source_text')) {
                $table->text('source_text')->nullable()->after('chunk_text');
            }
            if (!Schema::hasColumn('knowledge_chunks', 'source_spans')) {
                $table->json('source_spans')->nullable()->after('source_text');
            }
            if (!Schema::hasColumn('knowledge_chunks', 'transformation_type')) {
                $table->string('transformation_type', 20)->default('extractive')->after('source_spans');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('knowledge_chunks')) {
            return;
        }

        Schema::table('knowledge_chunks', function (Blueprint $table) {
            if (Schema::hasColumn('knowledge_chunks', 'transformation_type')) {
                $table->dropColumn('transformation_type');
            }
            if (Schema::hasColumn('knowledge_chunks', 'source_spans')) {
                $table->dropColumn('source_spans');
            }
            if (Schema::hasColumn('knowledge_chunks', 'source_text')) {
                $table->dropColumn('source_text');
            }
        });
    }
};
