<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop existing Passport tables if they were created by earlier migrations
        // so we can recreate them with UUID schemas.
        foreach ([
            'oauth_auth_codes',
            'oauth_access_tokens',
            'oauth_refresh_tokens',
            'oauth_device_codes',
            'oauth_clients',
        ] as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }
    }

    public function down(): void
    {
        // No-op: these tables will be recreated by the subsequent create_* migrations
    }

    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};

