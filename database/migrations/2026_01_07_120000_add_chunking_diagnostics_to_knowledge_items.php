<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('knowledge_items')) {
            return;
        }

        Schema::table('knowledge_items', function (Blueprint $table) {
            if (!Schema::hasColumn('knowledge_items', 'chunking_status')) {
                $table->string('chunking_status', 50)->nullable()->after('normalized_claims')->index();
            }
            if (!Schema::hasColumn('knowledge_items', 'chunking_skip_reason')) {
                $table->string('chunking_skip_reason', 100)->nullable()->after('chunking_status')->index();
            }
            if (!Schema::hasColumn('knowledge_items', 'chunking_error_code')) {
                $table->string('chunking_error_code', 50)->nullable()->after('chunking_skip_reason');
            }
            if (!Schema::hasColumn('knowledge_items', 'chunking_error_message')) {
                $table->text('chunking_error_message')->nullable()->after('chunking_error_code');
            }
            if (!Schema::hasColumn('knowledge_items', 'chunking_metrics')) {
                $table->json('chunking_metrics')->nullable()->after('chunking_error_message');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('knowledge_items')) {
            return;
        }

        Schema::table('knowledge_items', function (Blueprint $table) {
            if (Schema::hasColumn('knowledge_items', 'chunking_metrics')) {
                $table->dropColumn('chunking_metrics');
            }
            if (Schema::hasColumn('knowledge_items', 'chunking_error_message')) {
                $table->dropColumn('chunking_error_message');
            }
            if (Schema::hasColumn('knowledge_items', 'chunking_error_code')) {
                $table->dropColumn('chunking_error_code');
            }
            if (Schema::hasColumn('knowledge_items', 'chunking_skip_reason')) {
                $table->dropColumn('chunking_skip_reason');
            }
            if (Schema::hasColumn('knowledge_items', 'chunking_status')) {
                $table->dropColumn('chunking_status');
            }
        });
    }
};
