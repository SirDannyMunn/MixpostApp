<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_canvas_document_versions', function (Blueprint $table) {
            $table->string('media_id')->nullable()->after('command_target');
        });
    }

    public function down(): void
    {
        Schema::table('ai_canvas_document_versions', function (Blueprint $table) {
            $table->dropColumn('media_id');
        });
    }
};
