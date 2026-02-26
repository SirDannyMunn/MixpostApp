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
     * Makes organization_id and created_by nullable to support community templates.
     * Adds 'comment' to the template_type check constraint.
     */
    public function up(): void
    {
        // Drop foreign key constraints
        Schema::table('templates', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['created_by']);
        });

        // Change columns to nullable
        Schema::table('templates', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable()->change();
            $table->uuid('created_by')->nullable()->change();
        });

        // Re-add foreign key constraints with SET NULL on delete
        Schema::table('templates', function (Blueprint $table) {
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();
            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        // Drop old check constraint and add new one with 'comment' type
        DB::statement('ALTER TABLE templates DROP CONSTRAINT templates_template_type_check');
        DB::statement("ALTER TABLE templates ADD CONSTRAINT templates_template_type_check CHECK (template_type::text = ANY (ARRAY['slideshow'::varchar, 'post'::varchar, 'story'::varchar, 'reel'::varchar, 'custom'::varchar, 'comment'::varchar]::text[]))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore original check constraint (without 'comment')
        DB::statement('ALTER TABLE templates DROP CONSTRAINT templates_template_type_check');
        DB::statement("ALTER TABLE templates ADD CONSTRAINT templates_template_type_check CHECK (template_type::text = ANY (ARRAY['slideshow'::varchar, 'post'::varchar, 'story'::varchar, 'reel'::varchar, 'custom'::varchar]::text[]))");

        Schema::table('templates', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['created_by']);
        });

        // Note: Cannot make non-nullable if null records exist
        // Would need to delete them first in production
        Schema::table('templates', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable(false)->change();
            $table->uuid('created_by')->nullable(false)->change();
        });

        Schema::table('templates', function (Blueprint $table) {
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->onDelete('cascade');
            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }
};
