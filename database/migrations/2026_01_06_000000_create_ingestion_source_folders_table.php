<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('ingestion_source_folders')) {
            Schema::create('ingestion_source_folders', function (Blueprint $table) {
                $table->uuid('id')->primary();

                $table->uuid('ingestion_source_id');
                $table->uuid('folder_id');
                $table->uuid('created_by')->nullable();
                $table->timestamp('created_at');

                $table->unique(['ingestion_source_id', 'folder_id'], 'isf_unique');
                $table->index('folder_id', 'isf_folder_idx');
                $table->index('ingestion_source_id', 'isf_source_idx');

                $table->foreign('ingestion_source_id', 'isf_ingestion_source_fk')
                    ->references('id')->on('ingestion_sources')
                    ->onDelete('cascade');

                $table->foreign('folder_id', 'isf_folder_fk')
                    ->references('id')->on('folders')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_source_folders');
    }
};
