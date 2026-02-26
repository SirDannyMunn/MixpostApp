<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('generation_snapshots', function (Blueprint $table) {
            if (!Schema::hasColumn('generation_snapshots', 'structure_resolution')) {
                $table->string('structure_resolution', 30)->nullable()->after('swipes');
            }
            if (!Schema::hasColumn('generation_snapshots', 'structure_fit_score')) {
                $table->integer('structure_fit_score')->nullable()->after('structure_resolution');
            }
            if (!Schema::hasColumn('generation_snapshots', 'resolved_structure_payload')) {
                $table->json('resolved_structure_payload')->nullable()->after('structure_fit_score');
            }
        });

        Schema::table('generation_snapshots', function (Blueprint $table) {
            $table->index(['organization_id', 'structure_resolution'], 'generation_snapshots_org_structure_resolution_idx');
        });
    }

    public function down(): void
    {
        Schema::table('generation_snapshots', function (Blueprint $table) {
            try { $table->dropIndex('generation_snapshots_org_structure_resolution_idx'); } catch (\Throwable) {}

            foreach (['structure_resolution','structure_fit_score','resolved_structure_payload'] as $col) {
                if (Schema::hasColumn('generation_snapshots', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
