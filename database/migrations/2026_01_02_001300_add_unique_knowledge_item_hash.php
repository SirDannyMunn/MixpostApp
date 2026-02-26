<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('knowledge_items')) {
            Schema::table('knowledge_items', function (Blueprint $table) {
                // Add strong unique constraint across org + content hash
                $table->unique(['organization_id', 'raw_text_sha256'], 'uniq_org_knowledge_hash');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('knowledge_items')) {
            Schema::table('knowledge_items', function (Blueprint $table) {
                $table->dropUnique('uniq_org_knowledge_hash');
            });
        }
    }
};

