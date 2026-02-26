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
            $table->string('traits_schema_version')->nullable()->after('traits');
            $table->text('style_preview')->nullable()->after('traits_schema_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('voice_profiles', function (Blueprint $table) {
            $table->dropColumn(['traits_schema_version', 'style_preview']);
        });
    }
};
