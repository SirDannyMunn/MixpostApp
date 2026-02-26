<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('ingestion_sources')) {
            Schema::table('ingestion_sources', function (Blueprint $table) {
                if (!Schema::hasColumn('ingestion_sources', 'title')) {
                    $table->string('title', 500)->nullable()->after('mime_type');
                }
                if (!Schema::hasColumn('ingestion_sources', 'metadata')) {
                    // Use jsonb where available; Laravel will handle json
                    $table->json('metadata')->nullable()->after('title');
                }
                if (!Schema::hasColumn('ingestion_sources', 'confidence_score')) {
                    $table->float('confidence_score')->nullable()->after('metadata');
                }
                if (!Schema::hasColumn('ingestion_sources', 'quality_score')) {
                    $table->float('quality_score')->nullable()->after('confidence_score');
                }
                if (!Schema::hasColumn('ingestion_sources', 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ingestion_sources')) {
            Schema::table('ingestion_sources', function (Blueprint $table) {
                if (Schema::hasColumn('ingestion_sources', 'deleted_at')) {
                    $table->dropSoftDeletes();
                }
                if (Schema::hasColumn('ingestion_sources', 'quality_score')) {
                    $table->dropColumn('quality_score');
                }
                if (Schema::hasColumn('ingestion_sources', 'confidence_score')) {
                    $table->dropColumn('confidence_score');
                }
                if (Schema::hasColumn('ingestion_sources', 'metadata')) {
                    $table->dropColumn('metadata');
                }
                if (Schema::hasColumn('ingestion_sources', 'title')) {
                    $table->dropColumn('title');
                }
            });
        }
    }
};

