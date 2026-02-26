<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('generation_snapshots', function (Blueprint $table) {
            $table->uuid('voice_profile_id')->nullable()->after('template_data');
            $table->string('voice_source', 32)->nullable()->after('voice_profile_id');
            $table->index('voice_profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('generation_snapshots', function (Blueprint $table) {
            $table->dropIndex(['voice_profile_id']);
            $table->dropColumn(['voice_profile_id', 'voice_source']);
        });
    }
};

