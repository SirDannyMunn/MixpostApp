<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $p = config('social-watcher.table_prefix', 'sw_');

        $addOrg = function (string $tableName, ?string $after = 'id'): void {
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $after) {
                if (!Schema::hasColumn($tableName, 'organization_id')) {
                    if ($after) {
                        $table->uuid('organization_id')->nullable()->after($after);
                    } else {
                        $table->uuid('organization_id')->nullable();
                    }
                }
            });

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'organization_id')) {
                    try {
                        $table->index('organization_id');
                    } catch (\Throwable) {
                        // best-effort
                    }
                }
            });
        };

        $addOrg($p . 'sources');
        $addOrg($p . 'content_items');
        $addOrg($p . 'content_metrics');
        $addOrg($p . 'content_tags');
        $addOrg($p . 'alert_rules');
        $addOrg($p . 'alerts');
        $addOrg($p . 'normalized_content');

        // Pivot table has no id column.
        $addOrg($p . 'content_item_tag', 'content_item_id');
    }

    public function down(): void
    {
        $p = config('social-watcher.table_prefix', 'sw_');

        $dropOrg = function (string $tableName): void {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                try {
                    $table->dropIndex(['organization_id']);
                } catch (\Throwable) {
                    // best-effort
                }

                if (Schema::hasColumn($tableName, 'organization_id')) {
                    $table->dropColumn('organization_id');
                }
            });
        };

        $dropOrg($p . 'content_item_tag');
        $dropOrg($p . 'normalized_content');
        $dropOrg($p . 'alerts');
        $dropOrg($p . 'alert_rules');
        $dropOrg($p . 'content_tags');
        $dropOrg($p . 'content_metrics');
        $dropOrg($p . 'content_items');
        $dropOrg($p . 'sources');
    }
};
