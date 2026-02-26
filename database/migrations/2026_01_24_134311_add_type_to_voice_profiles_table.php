<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('voice_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('voice_profiles', 'type')) {
                $table->string('type', 32)->default('inferred')->after('user_id');
                $table->index('type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('voice_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('voice_profiles', 'type')) {
                $table->dropIndex(['type']);
                $table->dropColumn('type');
            }
        });
    }
};
