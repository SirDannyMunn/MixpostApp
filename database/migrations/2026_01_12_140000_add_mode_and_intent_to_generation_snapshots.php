<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('generation_snapshots', function (Blueprint $table) {
            if (!Schema::hasColumn('generation_snapshots', 'intent')) {
                $table->string('intent', 120)->nullable()->after('classification');
            }
            if (!Schema::hasColumn('generation_snapshots', 'mode')) {
                $table->json('mode')->nullable()->after('intent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('generation_snapshots', function (Blueprint $table) {
            if (Schema::hasColumn('generation_snapshots', 'mode')) {
                $table->dropColumn('mode');
            }
            if (Schema::hasColumn('generation_snapshots', 'intent')) {
                $table->dropColumn('intent');
            }
        });
    }
};
