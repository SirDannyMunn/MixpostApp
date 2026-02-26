<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('voice_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('voice_profiles', 'status')) {
                $table->string('status', 32)->nullable()->after('sample_size');
                $table->index('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('voice_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('voice_profiles', 'status')) {
                $table->dropIndex(['status']);
                $table->dropColumn('status');
            }
        });
    }
};
