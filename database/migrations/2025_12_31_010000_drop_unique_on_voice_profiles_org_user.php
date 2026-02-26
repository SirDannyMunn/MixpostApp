<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('voice_profiles')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            // Laravel executes Blueprint statements after the callback returns, so try/catch
            // inside the callback won't catch missing-constraint errors.
            DB::statement('ALTER TABLE voice_profiles DROP CONSTRAINT IF EXISTS voice_profiles_organization_id_user_id_unique');
            return;
        }

        // Other drivers
        Schema::table('voice_profiles', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('voice_profiles', function (Blueprint $table) {
            // Restore the unique index if rolling back
            $table->unique(['organization_id','user_id']);
        });
    }
};
