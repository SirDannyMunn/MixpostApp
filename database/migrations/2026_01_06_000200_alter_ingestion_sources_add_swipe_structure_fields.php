<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ingestion_sources', function (Blueprint $table) {
            if (!Schema::hasColumn('ingestion_sources', 'swipe_structure_id')) {
                $table->uuid('swipe_structure_id')->nullable()->after('quality_score');
            }
            if (!Schema::hasColumn('ingestion_sources', 'structure_status')) {
                $table->string('structure_status', 20)->default('none')->after('swipe_structure_id');
            }
            if (!Schema::hasColumn('ingestion_sources', 'structure_confidence')) {
                $table->integer('structure_confidence')->nullable()->after('structure_status');
            }
        });

        Schema::table('ingestion_sources', function (Blueprint $table) {
            $table->index(['organization_id', 'source_type'], 'ingestion_sources_org_type_idx');
            $table->index(['organization_id', 'swipe_structure_id'], 'ingestion_sources_org_swipe_structure_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ingestion_sources', function (Blueprint $table) {
            try { $table->dropIndex('ingestion_sources_org_type_idx'); } catch (\Throwable) {}
            try { $table->dropIndex('ingestion_sources_org_swipe_structure_idx'); } catch (\Throwable) {}

            foreach (['swipe_structure_id','structure_status','structure_confidence'] as $col) {
                if (Schema::hasColumn('ingestion_sources', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
