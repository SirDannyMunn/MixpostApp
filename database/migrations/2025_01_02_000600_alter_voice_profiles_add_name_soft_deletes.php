<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('voice_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('voice_profiles', 'name')) {
                $table->string('name')->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('voice_profiles', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('voice_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('voice_profiles', 'name')) {
                $table->dropColumn('name');
            }
            if (Schema::hasColumn('voice_profiles', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};

