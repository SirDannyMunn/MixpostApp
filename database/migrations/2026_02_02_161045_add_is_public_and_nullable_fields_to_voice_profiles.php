<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Makes organization_id and user_id nullable to support community voice profiles.
     * Adds is_public flag for community sharing.
     * Adds category field for filtering (post, comment, etc).
     */
    public function up(): void
    {
        // Drop foreign key constraints first
        Schema::table('voice_profiles', function (Blueprint $table) {
            // user_id has a foreign key
            $table->dropForeign(['user_id']);
        });

        // Make columns nullable and add new columns
        Schema::table('voice_profiles', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable()->change();
            $table->uuid('user_id')->nullable()->change();
        });

        // Add new columns
        Schema::table('voice_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('voice_profiles', 'is_public')) {
                $table->boolean('is_public')->default(false)->after('is_default');
            }
            if (!Schema::hasColumn('voice_profiles', 'category')) {
                $table->string('category', 50)->nullable()->after('type');
            }
            if (!Schema::hasColumn('voice_profiles', 'usage_count')) {
                $table->integer('usage_count')->default(0)->after('is_public');
            }
        });

        // Re-add foreign key with nullable support
        Schema::table('voice_profiles', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        // Add index for public voice profiles
        Schema::table('voice_profiles', function (Blueprint $table) {
            $table->index(['is_public', 'type', 'category'], 'voice_profiles_public_type_category_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('voice_profiles', function (Blueprint $table) {
            $table->dropIndex('voice_profiles_public_type_category_idx');
        });

        Schema::table('voice_profiles', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('voice_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('voice_profiles', 'is_public')) {
                $table->dropColumn('is_public');
            }
            if (Schema::hasColumn('voice_profiles', 'category')) {
                $table->dropColumn('category');
            }
            if (Schema::hasColumn('voice_profiles', 'usage_count')) {
                $table->dropColumn('usage_count');
            }
        });

        Schema::table('voice_profiles', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable(false)->change();
            $table->uuid('user_id')->nullable(false)->change();
        });

        Schema::table('voice_profiles', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }
};
