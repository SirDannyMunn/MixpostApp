<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Robustly drop the unique constraint/index regardless of how it was created
        // Postgres: constraints are named; unique also creates an index with same name by default
        DB::statement('ALTER TABLE voice_profiles DROP CONSTRAINT IF EXISTS voice_profiles_organization_id_user_id_unique');
        DB::statement('DROP INDEX IF EXISTS voice_profiles_organization_id_user_id_unique');
    }

    public function down(): void
    {
        // Recreate the unique constraint if rolled back
        DB::statement('ALTER TABLE voice_profiles ADD CONSTRAINT voice_profiles_organization_id_user_id_unique UNIQUE (organization_id, user_id)');
    }
};

