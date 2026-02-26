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
            if (!Schema::hasColumn('knowledge_chunks', 'chunk_kind')) {
                $table->string('chunk_kind', 20)->default('fact')->after('chunk_role');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('knowledge_chunks')) {
            return;
        }

        Schema::table('knowledge_chunks', function (Blueprint $table) {
            if (Schema::hasColumn('knowledge_chunks', 'chunk_kind')) {
                $table->dropColumn('chunk_kind');
            }
        });
    }
};
