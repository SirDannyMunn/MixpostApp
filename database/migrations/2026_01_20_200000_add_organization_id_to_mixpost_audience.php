<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add organization_id to mixpost_audience for multi-tenant support.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('mixpost_audience', 'organization_id')) {
            Schema::table('mixpost_audience', function (Blueprint $table) {
                $table->uuid('organization_id')->nullable()->after('id');
                $table->index('organization_id', 'mixpost_audience_org_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mixpost_audience', 'organization_id')) {
            Schema::table('mixpost_audience', function (Blueprint $table) {
                $table->dropIndex('mixpost_audience_org_idx');
                $table->dropColumn('organization_id');
            });
        }
    }
};
