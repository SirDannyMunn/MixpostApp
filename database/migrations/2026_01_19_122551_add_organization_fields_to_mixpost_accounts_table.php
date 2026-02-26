<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds organization context to Mixpost accounts table for multi-tenant support.
     */
    public function up(): void
    {
        Schema::table('mixpost_accounts', function (Blueprint $table) {
            // Organization context - nullable for backwards compatibility
            $table->foreignUuid('organization_id')
                ->nullable()
                ->after('uuid')
                ->constrained('organizations')
                ->onDelete('cascade');
            
            // User who connected this account
            $table->foreignUuid('connected_by')
                ->nullable()
                ->after('access_token')
                ->constrained('users')
                ->onDelete('set null');
            
            // When the account was connected
            $table->timestamp('connected_at')
                ->nullable()
                ->after('connected_by');
            
            // Update the unique constraint to include organization_id
            // Drop old constraint first, then add new one
            $table->dropUnique('accounts_unq_id');
            $table->unique(['organization_id', 'provider', 'provider_id'], 'accounts_org_unq_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mixpost_accounts', function (Blueprint $table) {
            // Restore original unique constraint
            $table->dropUnique('accounts_org_unq_id');
            $table->unique(['provider', 'provider_id'], 'accounts_unq_id');
            
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
            
            $table->dropForeign(['connected_by']);
            $table->dropColumn('connected_by');
            
            $table->dropColumn('connected_at');
        });
    }
};
