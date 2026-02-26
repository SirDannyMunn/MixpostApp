<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('voice_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('voice_profiles', 'traits_preview')) {
                $table->string('traits_preview', 255)->nullable()->after('traits');
            }
            if (!Schema::hasColumn('voice_profiles', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('traits_preview');
            }
        });

        // Enforce at most one default per organization for non-deleted rows (Postgres partial unique index)
        try {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS voice_profiles_one_default_per_org ON voice_profiles (organization_id) WHERE is_default = true AND deleted_at IS NULL');
        } catch (Throwable $e) {
            // Ignore if the database does not support partial indexes or statement fails
        }
    }

    public function down(): void
    {
        // Drop the partial unique index if it exists
        try {
            DB::statement('DROP INDEX IF EXISTS voice_profiles_one_default_per_org');
        } catch (Throwable $e) {
            // Ignore
        }

        Schema::table('voice_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('voice_profiles', 'is_default')) {
                $table->dropColumn('is_default');
            }
            if (Schema::hasColumn('voice_profiles', 'traits_preview')) {
                $table->dropColumn('traits_preview');
            }
        });
    }
};

